# Design: team RBAC — scoped, role-assumption access for existing IAM users

> Status: **draft, for review.** This is a design proposal, not shipped behaviour. No
> code in this PR — it captures the direction so the build can be scoped deliberately.
> Tracking: LPX-635.

## TL;DR

- Teams already exist as IAM users. We grant access **not by minting a new principal**,
  but by controlling **which roles each existing user may assume**.
- YOLO already provisions scoped tier roles (`observer` / `deployer` / `admin`) and each
  one already trusts `account:root` + `sts:AssumeRole`, with the design note that *the
  identity-side `sts:AssumeRole` grant is the intended gate*. The RBAC layer is the
  socket that was deliberately left open.
- The grant is expressed as **convention-named IAM groups** that allow `sts:AssumeRole`
  on a specific scoped role ARN. **Membership is the only lever** — add a user to a group
  to grant, remove to revoke. YOLO never creates or owns users.
- Granularity is **per-app** (an operator can be granted app-foo but not app-bar in the
  same environment), which is why the read/admin tiers move to app scope (see below).
- Strip a team user's *direct* permissions and their effective access becomes **exactly
  their group set** — which is the agent-safety win (an agent running as that user cannot
  exceed it) and the central control, in one move.
- Break-glass collapses to "an `admin`-group member assumes the admin role"; there is **no
  `--sudo` / `--dangerously-skip-permissions` flag**. The AWS Console (human + MFA) is the
  always-available, agent-unreachable kill switch.

## Problem

We want to manage a team's access to **apps / environments / the account** from one place,
against the IAM users that already exist, without every developer spinning up a parallel
identity. Access should be grantable and revocable centrally, at per-app granularity, and
layered on top of YOLO's existing tier model.

A secondary requirement falls out of the same mechanism: when an agent (or any automation)
runs *as* a developer, it must not be able to exceed what that developer is allowed to do.

## Principle

Power lives in **roles**, not identities. A daily identity is deliberately weak; it
**assumes up** into a scoped role to do anything real. A principal's access is therefore
defined entirely by **which roles it may assume** — and that is a thing we can grant and
revoke centrally, without touching the identity itself.

This is the standard least-privilege posture, and YOLO is already built for it.

## What already exists (the socket left open)

| Resource | Scope | Name | Trust |
| --- | --- | --- | --- |
| `ObserverRole` | `Env` | `yolo-{env}-observer-role` | `account:root` + `sts:AssumeRole` |
| `DeployerRole` | **`App`** | `yolo-{env}-{app}-deployer` | OIDC (CI) **and** `account:root` + `sts:AssumeRole` (local) |
| `AdminRole` | `Env` | `yolo-{env}-admin-role` | `account:root` + `sts:AssumeRole` |

Each role's permission policy *is* the tier (`ObserverPolicy` / `DeployerPolicy` /
`AdminPolicy`). A command declares its tier via a marker interface (`ReadOnlyCommand` /
`DeployerCommand` / `AdminCommand`); `Command::mintTierCredentials()` assumes the matching
role at startup and re-registers every AWS client against the scoped credentials, so YOLO
can never exceed the tier even when the developer authenticated as their broader self.

Two gaps today:

1. **`mintTierCredentials()` is fail-open and self-deactivating.** It returns early if the
   role doesn't exist, and on any assume failure it falls back to the developer's full
   profile with a warning. So the cap is advisory, not enforced. (See *Lever A*.)
2. **`account:root` trust is wide.** It means *any* identity in the account that is itself
   granted `sts:AssumeRole` on the role may assume it. There is no identity-side grant yet,
   so in practice the gate is unbuilt. (See *the grant layer*.)

Crucially, the trust model is **already correct** — the roles defer the decision of "who may
assume me" to an identity-side grant. We are filling that grant in, not redesigning trust.

## The grant layer (new)

A grant is *"principal P may assume scoped role R."* Express it as an **IAM group** whose
only statement is `sts:AssumeRole` on R's ARN, named by the same convention YOLO already
uses for the role:

```
yolo-{scope-instance}-{tier}
```

| Group | Allows assuming | Add a member ⇒ |
| --- | --- | --- |
| `yolo-production-observer` | `yolo-production-observer-role` | read all of production |
| `yolo-staging-app-foo-deployer` | `yolo-staging-app-foo-deployer` | deploy app-foo to staging |
| `yolo-production-admin` | `yolo-production-admin-role` | sync/scale production |
| `yolo-account-admin` | the account-tier role | run `sync:account` |

- **Membership is the entire access-management UX.** `aws iam add-user-to-group` (or the
  console, or a future `yolo access` verb) grants; remove revokes. No identity is created.
- YOLO **provisions and syncs the groups + their assume-policies** alongside the roles it
  already manages — declaratively, reconciled by `sync`, never drifting. It does **not**
  manage membership: that is the human lever, held by the account owner.
- The group's policy is pure and deterministic (a role ARN built from account/env/app), so
  it survives the sync two-pass contract with nothing created yet.

## Per-app granularity

The grant matrix is **principal × scope-instance × tier**, where a scope-instance is an
app, an environment, or the account — YOLO's existing three-scope model gives the columns
for free. Per-app means the read and admin tiers must also be expressible at app scope, so
a grant can name one app:

- **Deployer** — already `Scope::App`. Per-app today. No change.
- **Observer** — move to a per-app variant so read access can be scoped to a single app
  (`yolo-{env}-{app}-observer-role`), its policy scoped to that app's resources by the
  `yolo:app` tag (the resources are already tagged, so this is ABAC, not new plumbing).
  An env-wide read role can remain as a coarser, separately-grantable option.
- **Admin** — `sync`/`scale` split naturally across scopes already (`sync:app` vs
  `sync:environment` vs `sync:account`). App-scoped admin grants `sync:app`/`scale` for one
  app; env/account admin remain inherently coarser because they touch shared infra. A grant
  names the scope it is for.

**Open design choice (flagged for review):** per-app observer/admin via *per-app roles*
(one role per app, simplest mental model, more roles) vs *ABAC on one role* (a single role
whose policy conditions on `yolo:app` matching a principal tag — fewer roles, more subtle).
The resources are already tagged for either. Recommendation: **per-app roles** to start —
they read plainly in `audit`/`sync` and match the existing deployer shape; revisit ABAC if
role count becomes unwieldy.

## Folded in: per-app deployer S3 least-privilege

Per-app scoping is not only about *who may assume the deployer role* — the role's
**permission policy** (`DeployerPolicy`) must also grant the **minimal** S3 set each deploy
step actually exercises on the app's own buckets, and no more. There is a known deployer
permission error on the app bucket to resolve as part of this.

The buckets are already per-app (`AssetBucket`, `S3ConfigBucket`, `S3Bucket` are all
`Scope::App`). Today the policy grants a **uniform, symmetric** `GetObject` + `PutObject` +
`ListBucket` set across *both* the asset and config buckets — broader than, and not aligned
to, what each step does:

| Step | Bucket | Minimal actions it needs | Scope |
| --- | --- | --- | --- |
| `PushAssetsToS3Step` | asset | `s3:PutObject` (covers CreateMultipartUpload / UploadPart / CompleteMultipartUpload + the immutable `CacheControl`), `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts` | **write-only**, `assetBucket/builds/*` — no read, no `ListBucket` |
| `RetrieveEnvFileStep` (build) | config | `s3:GetObject` | **read-only**, the env-file key |

So the target shape is: **asset = write-only on `builds/*`**, **config = read-only on the env
file** — not the current read + write + list on both. This is the same least-privilege
discipline `DeployerPolicy` already applies to ECS/ECR/Route 53, extended to S3.

**Action for the build:** recompute the S3 statements per step and resolve the AccessDenied.
The exact failing action/step should be confirmed against a real deploy log so the fix is
surgical — multipart upload is already covered by `s3:PutObject`, so the error is likely a
narrower gap (a specific key prefix, `GetBucketLocation`, or the optional storage bucket),
not the bulk of the push.

## Lever A — make the cap real (fail-closed)

Replace the fail-open no-op in `mintTierCredentials()` with a single path:

```
assume the tier role  → success → run capped, proceed
                      → failure → REFUSE (do not fall back to the full profile)
```

- Drop the `if (! $role->exists()) return;` branch. There is no "role absent" steady state
  — the role is declarative and `sync` (re)creates it. A genuinely fresh environment is
  bootstrapped once (see *rollout*), and from then on the role always exists.
- A minting failure (broken trust, revoked grant, deleted role) **refuses** rather than
  silently running uncapped. That closes the silent-escape hole: deleting the role no longer
  drops you back to full power.
- This is an unconditional enforce: the day it ships, a tiered command refuses until each
  environment has been bootstrapped once. That is a deliberate, ~one-step-per-env flag-day,
  not a shim — honest cap from minute one.

## Lever B — assume-only identities (the real agent fence)

A scary flag name is **not** a control — an agent (or a script) can type it as easily as a
human. The only thing that actually prevents reaching full power is removing full power from
the identity the agent can reach.

- Scope a team user's IAM identity so its **only** permission is `sts:AssumeRole` on the
  tier roles it has been granted (via the groups above). It has **no direct permissions**.
- Now the user's effective access **is** exactly their group set. An agent running as that
  user holds assume-only credentials and can do nothing the groups don't allow — even if it
  bypasses YOLO and calls AWS directly. There is no "full profile" to escape to.
- Note: access keys inherit the *user's* permissions (there is no per-key policy), and
  `sts get-session-token` cannot narrow them — so the constraint must live on the user. This
  is why the lever is *assume-only identities*, not *scoped keys*.

Lever B is what makes the central control real: a developer's reach is defined entirely by
the groups you put them in, enforced by AWS.

## Break-glass — there isn't a flag

Under Lever B, "skip the cap" is meaningless — skip it and you hold assume-only credentials
that can do nothing. So break-glass is **not** a YOLO flag. It is an out-of-band credential:

- **The AWS Console** — a browser + human SSO/MFA login is a separate auth path no agent can
  drive. Locked out at the CLI? Log in as a human and fix the IAM. Always-available kill
  switch.
- **An MFA-gated admin profile** (`~/.aws/config` `role_arn` + `mfa_serial`) — `aws --profile
  admin …` assumes up, prompting for MFA the agent can't supply.
- **A sealed emergency credential** in a password manager, biometric-gated.

So the entire `--sudo` vs `--dangerously-skip-permissions` naming question **dissolves** —
the danger lives where AWS already enforces a human factor, not behind a CLI flag.

## Known wrinkle — MFA freshness

If we ever gate a break-glass assume purely on `aws:MultiFactorAuthPresent`, note that a
session minted with MFA at login keeps that condition true for the whole token lifetime
(~1h) — so an agent inheriting that session would satisfy it too. "Fresh human presence"
needs either a **separate principal** the agent's session can't assume from, or a **local
biometric prompt** at break-glass time. This doesn't affect the identity split; it's a
detail for whichever break-glass path we lean on.

## Rollout

1. Provision groups + the fail-closed assertion behind the existing tier roles.
2. Bootstrap each environment once (the roles already exist for adopted envs; a fresh env is
   synced once by an account owner). After that the role always exists.
3. Migrate team identities to assume-only as a deliberate step (this is the flag-day — plan
   it like a migration, not a silent flip).
4. End state: **Identity Center / SSO**, where the daily identity is a low-priv permission
   set and admin is a separate permission set requiring a fresh human login — the IAM-user
   nuance disappears entirely.

## Non-goals (not this PR)

- No code. Design only.
- No membership management UI/verb decision locked (`yolo access grant …` is a candidate
  follow-up; IAM console is fine day-one).
- No change to the OIDC CI deploy path — it already works and is unaffected.

## Open questions for review

1. **Per-app observer/admin: per-app roles vs ABAC-on-tag?** (Rec: per-app roles to start.)
2. **Membership management: IAM console day-one, or a `yolo access` verb in the same pass?**
3. **Assume-only migration sequencing** — strip direct perms in the same release as the
   fail-closed assertion, or stage it after groups land and are populated?
4. **Account-tier admin** — is account scope a single `yolo-account-admin` group (a tiny set
   of people), or does it warrant finer split?
5. **Deployer S3 least-privilege** — what is the exact AccessDenied (step + action) on the app
   bucket? Confirming it from a deploy log lets the recompute be surgical rather than a guess.
