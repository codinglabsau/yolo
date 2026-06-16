# Team RBAC — scoped, role-assumption access for existing IAM users

> Status: **shipped (LPX-680).** This records the design *as built* — the original
> proposal was refined through review; the decisions below are what landed.

## Problem

Manage a team's access to apps / environments / the account from one place,
against the IAM users that already exist, without minting a parallel identity per
person. And — because YOLO is agent-driven — when an agent runs *as* a developer
it must not be able to exceed what that developer is allowed to do.

## Principle

Power lives in **roles**, not identities. A daily identity is weak; it *assumes
up* into a scoped tier role to do anything. Access is therefore "which roles may a
principal assume" — grantable and revocable centrally without touching the
identity. YOLO already mints a scoped tier role per command
(`Command::mintTierCredentials()`); this fills in the grant side.

## What shipped

| Tier | Role(s) | Scope reality | Grant group |
| --- | --- | --- | --- |
| **Observer** | `yolo-{env}-observer-role` (env) **and** `yolo-{env}-{app}-observer-role` (app) | **Scope-aware.** Per-app reads (`status`, `status:logs`) cap to the app role with **log content fenced to the app's log group**; env-wide reads (`status:environment`, every `audit`) cap to the env role. Log content is the *only* observer read AWS scopes — cost/metrics/topology are unscopeable collection ops, so they stay env-wide either way. | `yolo-{env}-observers`, `yolo-{env}-{app}-observers` |
| **Deployer** | `yolo-{env}-{app}-deployer` (app) | Already per-app — and genuinely effective: deploy mutations (UpdateService/RunTask/PutObject) DO scope to the app's resources. | `yolo-{env}-{app}-deployers` |
| **Admin** | `yolo-{env}-admin-role` (env, carries account-tier perms too) | **Coarse by design.** Its writes narrow by *service* to `yolo-*`, not per-resource, and `sync:environment`/`sync:account` touch shared infra — so per-app admin can't enforce anything. One env admin role covers sync/scale + the account-tier sync. | `yolo-{env}-admins` |

**Why not per-app across the board?** Only the deployer can be meaningfully
per-app (its mutations scope to resources). Per-app observer would be theatre
*except* for log content (the one scopeable, sensitive read) — so the per-app
observer role exists solely to fence logs. Per-app admin can't enforce anything,
so admin stays env-scoped.

## The grant layer

A grant = "principal P may assume tier role R", expressed as a YOLO-managed IAM
group whose single inline policy allows `sts:AssumeRole` on R. **Membership is the
entire access UX** — add a user to grant, remove to revoke. Managed by
[`yolo permissions <env>`](../docs/reference/commands.md) (or the IAM console).
YOLO provisions and reconciles the groups + their policy; it never manages
membership, and never creates or owns the users. IAM groups aren't taggable, so
ownership lives in the name and `yolo audit` is blind to them (like scaling
policies) — sync-drift is the stray-catcher.

The admin tier can manage `yolo-*` group membership (`AddUserToGroup` /
`RemoveUserFromGroup`, fenced to `yolo-*`) — so a member of `yolo-{env}-admins`
can grant access to others. Deliberate for a small senior team.

## Lever A — fail-closed cap

`mintTierCredentials()` assumes the tier role or **refuses** — no silent
fall-through to the full identity. The single escape is the global
`--dangerously-skip-permissions` flag (named ugly to resist reflexive agent use,
loud warning), which is also the once-per-env bootstrap that creates the roles +
groups (`yolo sync <env> --dangerously-skip-permissions`).

## Lever B — assume-only identities (not built; the real agent fence)

A flag name is not a control — an agent can type it too. The only thing that
truly stops an agent exceeding its developer is removing full power from the
identity: scope each team user to `sts:AssumeRole`-only (no direct perms), so
their effective access *is* their group set, enforced by AWS, with no full profile
to escape to. This is an IAM-posture migration, not code — deliberately staged
after groups land and are populated, one identity at a time. Under Lever B the
break-glass flag becomes meaningless and break-glass collapses to an out-of-band
path (AWS Console + human MFA, agent-unreachable).

## Rollout

1. Merge + bootstrap each env once with `--dangerously-skip-permissions` (creates
   the tier roles + grant groups). From then on the cap enforces.
2. Populate group membership via `yolo permissions` / the console.
3. (Future) Lever B: strip team identities to assume-only — one identity first.
4. End state: Identity Center / SSO, where the daily identity is a low-priv
   permission set and admin is a separate set requiring a fresh human login. The
   grant groups + assume-only IAM users are scaffolding SSO then replaces; the
   tier roles + the fail-closed cap carry over unchanged.

## Open / non-goals

- **Lever B** is an IAM-posture call, not in this work.
- **No change to the OIDC CI deploy path** — it already works and is unaffected.
- **Deployer S3** was tightened to least-privilege alongside this (asset
  write-only on `builds/*`, config read-only on the env-file key).
