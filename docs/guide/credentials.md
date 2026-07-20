# Developer Credentials

YOLO authenticates to AWS as **you** — a named profile per environment on each developer's machine (see [Getting Started](/guide/getting-started#_3-point-yolo-at-aws)). This page covers the team side of that: creating an IAM user for a new developer, granting them a tier, and setting their machine up with short-lived, MFA-forwarding credentials — one interactive [`yolo configure`](/reference/commands#yolo-configure) run.

## Who can do what

Access is granted by **grant-group membership**, never by attaching policies to a user (see [conventions](/reference/commands#conventions)). Each group allows `sts:AssumeRole` on exactly one scoped tier role, plus a self-service slice scoped to the member's own user: enrolling an MFA device (allowed without MFA — a new user must be able to bootstrap), and managing their own access keys and password (**MFA required** — a leaked credential can't cut itself a replacement or remove the device):

| Tier | Group | Grants |
|---|---|---|
| Observer — environment | `yolo-{env}-observers` | read every app in the environment |
| Observer — one app | `yolo-{env}-{app}-observers` | read one app (log content fenced to its log group) |
| Deployer — one app | `yolo-{env}-{app}-deployers` | build and deploy one app |
| Admin — environment | `yolo-{env}-admins` | `sync` / `scale` / manage access (**fresh MFA code per run**) |

Broader tiers **subsume narrower ones in the grant itself**: commands always mint the least-privileged role for their job (reads mint observer roles, deploys the per-app deployer) regardless of who runs them — so the env observers grant includes every per-app observer role, and the admins grant includes the whole hierarchy. An admin or env observer runs app-scoped commands (`status`, `db:tunnel`) without per-app membership; only the admin-role assume itself prompts for a fresh MFA code.

**Every tier requires MFA to assume** — the trust condition is on all four roles, AWS-enforced, so a bare static key can't hold even read-only access. Sessions minted by the `yolo-credentials-1password` helper carry the MFA context automatically; only the admin tier adds a per-run prompt. Most developers want **environment observer + deployer on the apps they ship**. Keep the admins group small.

## Onboard a developer

Onboarding is split cleanly in two: the **admin** creates the user and grants tiers (a few minutes, once), then the **developer** does everything else self-service — enrolling MFA, creating their own access key, and configuring their machine. The order matters: group membership carries the self-service permissions, so the admin's half must be complete before the developer's half can start.

### Admin: create the IAM user

YOLO never creates or owns users — an account admin does this once per person, in the console or CLI:

- Create the user with a **console password** and **no access key**. Untick *"user must create a new password at next sign-in"* — the password change is MFA-gated, so a forced pre-MFA reset would be denied; the developer changes it themselves once enrolled. That's it: no policies to attach, no MFA device to register, no key to hand over.

### Admin: grant tiers

From the app's directory, a member of `yolo-{env}-admins` runs:

```bash
yolo permissions production
```

Pick the user, tick the tiers, confirm. Membership is the entire access lever — the same command revokes by unticking. See [`yolo permissions`](/reference/commands#yolo-permissions).

This step is not just about tier access: the grant groups also carry the developer's **self-service permissions** (enrol MFA, manage own keys). A user in no group can't even add a device — so hand over the console password only after this step.

### Developer: enrol MFA and create your access key

Self-service in the console, in an order that keeps MFA structural — the access key can't exist before the device does:

1. Sign in to the console with the password from your admin.
2. Enrol an **MFA device** — choose **Authenticator app** (TOTP), not a passkey (allowed pre-MFA — the bootstrap path). Name it anything you like (names are account-unique, so make it yours). Scan the QR into 1Password — the same seed later provides the TOTP field the credential helper forwards.
3. **Sign out and back in**, entering a one-time code at the prompt. Enrolling doesn't upgrade your current session — only a fresh MFA sign-in carries the MFA context. If you aren't prompted for a code, the device didn't attach; go back to step 2.
4. Create your own **access key** (and change your password) — both MFA-gated, so they only work from the re-signed-in session.

An MFA device is not optional — every YOLO tier's trust policy denies AssumeRole without MFA, and `yolo configure` refuses to finish without a device. From here on you rotate your own keys the same way: any MFA-carrying session may create, deactivate, and delete them.

::: warning Passkeys don't work for the CLI — a TOTP device is mandatory
AWS only accepts **TOTP codes** for API/CLI MFA (`sts:GetSessionToken` takes a `--token-code`; a passkey can't produce one — WebAuthn has no OTP). A user with only a passkey signs in to the console fine but can never mint an MFA session, so no YOLO tier is assumable and the admin per-run prompt has nothing to enter. Enrol the **authenticator-app (TOTP) device first** — it's the one YOLO depends on. A passkey may be added *alongside* it for console sign-in, but note the credential helper picks the **first listed device**, so if minting starts failing after adding one, the passkey's serial is likely being paired with a TOTP code.
:::

### Developer: store the keys in 1Password

Store the access key in a 1Password item (your private **Employee** vault in a 1Password Business account) with these fields:

- `aws_access_key_id`
- `aws_secret_access_key`
- a **one-time-password** (TOTP) field — the QR scanned during MFA enrolment. It must be the *same seed* as the IAM device, or the helper forwards codes AWS rejects.

The long-lived key lives only in 1Password — it never sits in `~/.aws/credentials`.

### Developer: configure the machine

Prerequisites — `yolo configure` verifies the whole chain live, so everything above must exist first:

- [ ] IAM user created, **in its grant group(s)**
- [ ] MFA device enrolled (TOTP, not a passkey)
- [ ] Access key created and stored in 1Password **with the TOTP field**

From any app directory, run:

```bash
yolo configure production
```

One interactive command wires the whole machine — see [`yolo configure`](/reference/commands#yolo-configure) for the reference. It:

1. **Checks the binaries** (`aws`, `jq`, `op`) and prints the Homebrew install one-liner for anything missing.
2. **Installs the `yolo-credentials-1password` helper** from the composer package to `~/.local/bin` — a stable path, since `~/.aws/config` outlives any one repository checkout. Re-running `configure` after a `composer update` refreshes it.
3. **Writes the AWS profile** (`credential_process` + the manifest's region) into `~/.aws/config`. Profiles map to AWS **accounts** — reuse one profile for every app in the same account.
4. **Detects the silent killers** before they bite: leftover `sso_*` keys in the profile (the CLI would try SSO and ignore `credential_process`) and a same-named section in `~/.aws/credentials` (static keys there **shadow** `credential_process`) — each is named and offered a fix.
5. **Verifies the 1Password item** has the required fields, sets `YOLO_<ENVIRONMENT>_AWS_PROFILE` in the app's `.env`, and **proves the chain** with a live `sts:GetCallerIdentity` held against the manifest's `account-id`.
6. **Enforces MFA** — checks the IAM user has a device registered *and* the item carries a TOTP to forward, and **fails if either is missing**. A session minted without MFA verifies green but can't assume any tier, so this would otherwise surface as an opaque AccessDenied at the first real command.

The result in `~/.aws/config`:

```ini
[profile my-app-production]
credential_process = /Users/you/.local/bin/yolo-credentials-1password "AWS my-app production"
region = ap-southeast-2
```

## Short-lived sessions with `yolo-credentials-1password`

The helper ([`bin/yolo-credentials-1password`](https://github.com/codinglabsau/yolo/blob/main/bin/yolo-credentials-1password)) reads the long-lived key from 1Password at mint time, calls `sts:GetSessionToken` — forwarding MFA automatically when the user has a device registered — and caches the short-lived session (4 hours, under `~/.aws/yolo-cache` with owner-only permissions) so the CLI doesn't re-prompt, and never reuses a TOTP, on every call. Long-lived keys never touch disk; only the expiring session does.

Its `credential_process` arguments: the 1Password item name, plus an optional second argument naming the vault (default `Employee`). Dependencies (`op`, the AWS CLI, `jq`) on macOS:

```bash
brew install awscli jq
brew install --cask 1password-cli
```

For `op` to authenticate through the desktop app (Touch ID instead of a separate sign-in), enable **Settings → Developer → Integrate with 1Password CLI** in 1Password.

::: info 1Password is a driver, not a requirement
`credential_process` only cares that the command emits credentials JSON on stdout — where the long-lived key comes from is up to you. `yolo configure --driver=process` accepts any such command (another password manager's CLI wrapped in a script, a corporate vault), and everything 1Password-specific in `yolo-credentials-1password` itself is the single `op item get` fetch near the top — adapt it by swapping that one call. Keep the properties that matter: the long-lived key is fetched at mint time and never written to disk, sessions are cached until near expiry, and MFA is forwarded when the user has a device.
:::

::: tip MFA is automatic
`yolo-credentials-1password` discovers the user's MFA device from AWS at mint time (`iam:ListMFADevices`) and takes the TOTP from the same 1Password item — no device ARN stored anywhere. A user with no device (or no TOTP field) gets a plain session, with a warning on stderr. Forwarding MFA to an account that doesn't enforce it is harmless — and future-proof if enforcement is turned on later.
:::

::: warning Not a secret store
The cache under `~/.aws/yolo-cache` holds working session credentials until they expire. It's `0700`/owner-only, but treat a lost laptop as a rotation event regardless — the exposure window is at most the 4-hour session, not the long-lived key.
:::

::: warning A pre-MFA session lingers in the cache
If the profile ever minted a session **before** the MFA device existed (or before the TOTP field was added), that plain session is cached for up to 4 hours — and AWS refuses **all IAM API calls** from a session minted without MFA, regardless of policy. The symptom is an `iam:ListMFADevices` / `iam:ListAccessKeys` denial that no permission change fixes. Delete the profile's file under `~/.aws/yolo-cache` and re-run; the fresh mint picks up the device.
:::
