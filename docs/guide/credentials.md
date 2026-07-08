# Developer Credentials

YOLO authenticates to AWS as **you** — a named profile per environment on each developer's machine (see [Getting Started](/guide/getting-started#_3-point-yolo-at-aws)). This page covers the team side of that: creating an IAM user for a new developer, granting them a tier, and setting their machine up with short-lived, MFA-forwarding credentials via the bundled `yolo-creds` helper.

## Who can do what

Access is granted by **grant-group membership**, never by attaching policies to a user (see [conventions](/reference/commands#conventions)). Each group allows `sts:AssumeRole` on exactly one scoped tier role:

| Tier | Group | Grants |
|---|---|---|
| Observer — environment | `yolo-{env}-observers` | read every app in the environment |
| Observer — one app | `yolo-{env}-{app}-observers` | read one app (log content fenced to its log group) |
| Deployer — one app | `yolo-{env}-{app}-deployers` | build and deploy one app |
| Admin — environment | `yolo-{env}-admins` | `sync` / `scale` / manage access (**MFA required**) |

Most developers want **environment observer + deployer on the apps they ship**. Keep the admins group small — its role trust requires MFA, AWS-enforced.

## Onboard a developer

### 1. Create the IAM user

YOLO never creates or owns users — an account admin does this once per person, in the console or CLI:

- Create the user with **no console password** (programmatic access only) and create one **access key**.
- If they'll ever hold the admin tier, register an **MFA device** on the user — the admin role's trust policy denies AssumeRole without it.
- Grant `iam:ListMFADevices` on self (a standard force-MFA policy carves this out) so tooling can discover the device without storing its ARN.

### 2. Grant tiers

From the app's directory, a member of `yolo-{env}-admins` runs:

```bash
yolo permissions production
```

Pick the user, tick the tiers, confirm. Membership is the entire access lever — the same command revokes by unticking. See [`yolo permissions`](/reference/commands#yolo-permissions).

### 3. Store the keys in 1Password

The developer stores the access key in a 1Password item (their private **Employee** vault in a 1Password Business account) with these fields:

- `aws_access_key_id`
- `aws_secret_access_key`
- a **one-time-password** (TOTP) field seeded from the IAM MFA device, if one is registered

The long-lived key lives only in 1Password — it never sits in `~/.aws/credentials`.

## Short-lived sessions with `yolo-creds`

YOLO ships a `credential_process` helper at `vendor/bin/yolo-creds` (installed by Composer alongside `vendor/bin/yolo`). It reads the long-lived key from 1Password at mint time, calls `sts:GetSessionToken` — forwarding MFA automatically when the user has a device registered — and caches the short-lived session (12 hours, under `~/.aws/yolo-cache` with owner-only permissions) so the CLI doesn't re-prompt, and never reuses a TOTP, on every call. Long-lived keys never touch disk; only the expiring session does.

Copy it somewhere stable — `~/.aws/config` outlives any one repository checkout:

```bash
cp vendor/bin/yolo-creds ~/.local/bin/yolo-creds
```

Then wire the profile:

```ini
# ~/.aws/config
[profile my-app-production]
credential_process = /Users/you/.local/bin/yolo-creds "AWS my-app production"
region = ap-southeast-2
```

The argument is the 1Password item name; an optional second argument names the vault (default `Employee`). It needs the [1Password CLI](https://developer.1password.com/docs/cli/) (`op`), the AWS CLI, and `jq`.

Point YOLO at the profile in the app's local `.env` and you're done:

```bash
# .env
YOLO_PRODUCTION_AWS_PROFILE=my-app-production
```

::: tip MFA is automatic
`yolo-creds` discovers the user's MFA device from AWS at mint time (`iam:ListMFADevices`) and takes the TOTP from the same 1Password item — no device ARN stored anywhere. A user with no device (or no TOTP field) gets a plain session, with a warning on stderr. Forwarding MFA to an account that doesn't enforce it is harmless — and future-proof if enforcement is turned on later.
:::

::: warning Not a secret store
The cache under `~/.aws/yolo-cache` holds working session credentials until they expire. It's `0700`/owner-only, but treat a lost laptop as a rotation event regardless — the exposure window is at most the 12-hour session, not the long-lived key.
:::
