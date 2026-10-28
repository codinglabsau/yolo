# Agent observer identity — read-only AWS access for coding agents

> Status: **proposal, for review.** Design only, no code.

## Problem

Coding agents (an AI assistant running in a developer's terminal or editor) are
useful operators for read work: `yolo status`, `yolo audit`, log and alarm reads,
raw `aws` describe calls while diagnosing an incident. Today the only credentials
on a developer's machine are the developer's own, so an agent works on whatever
the developer holds:

- **Read commands are already safe.** Every read mints the least-privileged
  observer role itself ([team RBAC](../docs/guide/credentials.md#who-can-do-what)).
- **Admin is gated per run** by a fresh MFA code — but the TOTP usually lives in
  the same password-manager item the credential helper reads, so the gate is a
  biometric prompt a human may approve on autopilot, not a hard boundary.
- **The developer tier is wide open.** `deploy`, `run`, `env:pull` and
  `env:push` need no per-run prompt. An agent on a developer's profile can read
  every secret the app holds and deploy from an uncommitted working directory.
- **Raw `aws` calls have nothing to use.** The base identity holds only the grant
  groups, so an agent's describe calls either fail or the agent needs a role
  profile the developer hand-writes.

Agent-harness permission rules (allow/ask/deny on command text) help, but they
match strings: `./vendor/bin/yolo deploy`, `php yolo deploy`, a script, or a raw
`aws sts assume-role` on the deployer role all slip past a `yolo deploy` rule.
And while the agent runs as the developer's OS user, it can read the developer's
cached session (`~/.aws/codinglabs-cache`) and ask the password manager for their
keys.

**Goal:** an agent gets read-only access to an environment's infrastructure on
an identity AWS itself caps, attributed to the developer whose agent it is,
without a parallel IAM user per developer.

## Options considered

| Option | Verdict |
| --- | --- |
| Agent on the developer's profile + harness permission rules | String matching; trivially bypassed. A backstop, not a boundary. |
| A chained observer profile (`role_arn` + `source_profile` = the developer) | Fixes raw `aws` reads, but the agent still holds the developer's base session and can assume anything they can. |
| One IAM user per developer per environment for their agent | Hard boundary, but N×M users, keys and MFA devices to manage — and noise in every IAM listing. |
| Lend the agent a down-scoped session the developer mints | No new identity and good attribution, but sessions expire mid-task (1h), so the developer re-mints constantly. |
| **One agent user per environment, shared through the password manager** | **Proposed.** One identity per environment, AWS-capped to read, attributed per developer via `SourceIdentity`. |

## Proposal

### 1. An env-scoped agent user, group and role (sync-owned)

`sync:environment` provisions, per environment:

| Resource | Name | Notes |
| --- | --- | --- |
| IAM user | `yolo-{env}-agent` | No console password. Sync never creates its access key (see §3). |
| Grant group | `yolo-{env}-agents` | Allows `sts:AssumeRole` on the agent role only, **conditioned on `sts:SourceIdentity` being set** (see §5). The user is its only member, and sync owns that membership — the one exception to "YOLO never manages membership", since YOLO owns the user too. |
| Tier role | `yolo-{env}-agent-role` | Carries the env `ObserverPolicy` and **nothing else** — no `data-read`, no `backups-read`. Trust requires MFA like every tier, plus `sts:SetSourceIdentity`. |

A dedicated role rather than the existing observer roles because data reads are
attached to the observer roles themselves (`AttachObserverRolePolicyStep`,
`AttachAppObserverRolePolicyStep`). An agent has no business reading user
uploads or database dumps.

The trade-off: per-app observer roles fence log content to one app's log group;
the agent role is env-wide, so an agent reads every app's logs in the
environment. Logs can carry user data, so this is an explicit acceptance, not an
oversight.

### 2. Tier minting knows it is an agent

`mintTierCredentials()` picks the observer role per command today. When the
caller is the env agent user (caller ARN = `user/yolo-{env}-agent`), every read
command mints `yolo-{env}-agent-role` instead, and every non-read command
refuses before attempting an assume, with a clear "agents are read-only" error
rather than an opaque AccessDenied.

### 3. Key and MFA live in one shared password-manager item

- An explicit admin command (working name `yolo agent:rotate <env>`, admin tier,
  per-run MFA) creates a fresh access key, deletes the old one, and writes both
  keys to the shared item. On first run it also enrols a virtual MFA device and
  stores its seed in the same item.
- The item lives in a dedicated vault (e.g. `YOLO Agents`) whose membership
  mirrors `yolo-{env}-observers`. The vault is the real access lever for agents.
- The existing `yolo-credentials-1password` helper works unchanged: it reads the
  keys and the TOTP from the item and mints an MFA-carrying session.

MFA on this identity proves "has access to the vault", not "a human is present".
That is acceptable for a read-only tier and keeps the every-tier-requires-MFA
invariant intact; it would not be acceptable for anything that writes.

Rotation is cheap — the key never touches disk — so rotate whenever someone
leaves the vault. `yolo permissions` should remind the admin when an observer is
removed.

### 4. `yolo configure --agent` writes the agent profile

`yolo configure <env> --agent`:

1. Runs as the developer first, proving their own chain as today.
2. Reads the developer's IAM username from `sts:GetCallerIdentity`
   (`arn:aws:iam::…:user/jane.doe`).
3. Writes a profile named **`{app}-{env}-agent`**. Configure owns the name, so
   a rule committed to an app repo matches on every machine:

   ```ini
   [profile my-app-production-agent]
   credential_process = /Users/you/.local/bin/yolo-credentials-1password "YOLO production agent" "YOLO Agents"
   role_session_name = agent-jane.doe@laptop-01
   region = ap-southeast-2
   ```

4. Proves the agent chain end to end: mint, assume the agent role with a
   `SourceIdentity`, `GetCallerIdentity`.

### 5. Attribution: `SourceIdentity` + session name

The agent user is shared, so its credentials alone cannot say whose agent made a
call. Configure captures the developer's identity while it still runs as them,
and yolo stamps it on every assume:

| Field | Value | Purpose |
| --- | --- | --- |
| `SourceIdentity` | `jane.doe` (IAM username) | **Who.** Persists across role chaining, cannot be changed for the life of the chain, and appears on every CloudTrail event. Audit keys on this. |
| `RoleSessionName` | `agent-jane.doe@laptop-01` | Who and where — useful when one person runs agents on several machines. Truncated to 64 characters. |

The IAM username beats `whoami`. It is verified against AWS at configure time,
unique across the team, and matches the developer's own CloudTrail events, so
their activity and their agent's line up. `whoami` collides across machines and
reads `root`/`node`/`runner` in containers.

Both values are **self-declared** once written to the profile — anyone in the
vault can claim any name. This is a tracing label, not authentication, which is
proportionate for a read-only tier.

The group policy's `sts:SourceIdentity` condition means an unstamped agent
session cannot be minted at all: a hand-written profile or a direct
`assume-role` without `--source-identity` is refused.

### 6. App-side enforcement (agent harness settings)

Each app repo commits harness settings that point agents at the agent profile.
yolo loads `.env` with `Dotenv::createImmutable`, so a real environment variable
wins over `.env` — the developer's terminal keeps their own profile, and only
agent sessions see the override. For example, in Claude Code's
`.claude/settings.json`:

```json
{
  "env": {
    "YOLO_STAGING_AWS_PROFILE": "my-app-staging-agent",
    "YOLO_PRODUCTION_AWS_PROFILE": "my-app-production-agent",
    "AWS_PROFILE": "my-app-staging-agent"
  },
  "permissions": {
    "deny": ["Read(~/.aws/codinglabs-cache/**)"],
    "ask": ["Bash(yolo deploy:*)", "Bash(yolo run:*)", "Bash(yolo env:pull:*)", "Bash(yolo env:push:*)"]
  }
}
```

A developer without the agent profile gets "profile not found" in agent
sessions — failing closed. The `ask` rules are a backstop only; the boundary is
the agent identity plus §7.

### 7. Fence the developer's own credentials

An agent identity only sets the default. While the agent runs as the
developer's OS user it can still pass `--profile <developer-profile>`. Closing
that needs the harness sandbox to deny:

- reads of the developer's session cache (`~/.aws/codinglabs-cache/**`), and
- password-manager access to the developer's personal vault.

For the second, the agent's `op` would authenticate as a **1Password service
account scoped to the agents vault** (`OP_SERVICE_ACCOUNT_TOKEN`). A service
account cannot see personal or employee vaults, so the agent cannot fetch the
developer's key or TOTP even if it calls `op` directly.

## Open questions

1. **Service-account token distribution.** The token is itself a shared secret
   per vault. How does it reach each machine without becoming the next
   long-lived secret on disk? (Keychain item read by a wrapper? Short-lived
   token minted per session?)
2. **Can the sandbox block `op`'s desktop-app integration while allowing the
   service account?** Untested. If it can't, §7 is only as strong as the
   developer's attention at the biometric prompt.
3. **Raw `aws` calls and `SourceIdentity`.** The AWS CLI does not appear to set
   `SourceIdentity` from a profile. If the group requires it, raw describe calls
   on the agent profile are refused. Options: drop the requirement (session name
   only for raw calls), or ship a small `credential_process` wrapper that assumes
   the agent role with `--source-identity` and emits the role session.
4. **Vault ↔ group drift.** Vault membership is a second source of truth
   alongside `yolo-{env}-observers`. Is a reminder in `yolo permissions` enough,
   or should `audit` flag an agent key older than the last observer-group change?
5. **Scope: per environment or per account?** IAM users are account-global.
   Environments that share an account get one agent user each under this
   proposal; one per account would halve the keys but blur the env-scoped grant.
6. **Env-wide logs.** Is env-wide log content (§1) acceptable for agents, or
   should the agent role carry per-app log fencing (one agent role per app,
   chosen by the command's app scope)?

## Non-goals

- **Agents that deploy.** Deploys stay with CI and humans. An agent that needs to
  ship opens a PR.
- **Write tiers for agents.** Nothing here weakens the admin or developer gates.
- **Per-developer agent IAM users.**
