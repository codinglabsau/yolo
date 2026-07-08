# Command Reference

Every YOLO command, with its arguments and options. Run `vendor/bin/yolo` with no arguments to list them, or `vendor/bin/yolo <command> --help` for Symfony's generated usage.

## Conventions

- **`<environment>`** — almost every command takes a required `environment` argument naming a key under `environments` in your `yolo.yml` (e.g. `production`, `staging`).
- **AWS authentication** — outside CI, YOLO reads a named AWS profile from `YOLO_<ENVIRONMENT>_AWS_PROFILE` in your local `.env`. Before any AWS call it verifies (via STS) that the profile resolves to the `account-id` declared in the manifest. The `default` profile is rejected. In CI it falls back to the AWS SDK default credential chain (GitHub OIDC, SSO).
- **Permission tiers** — you authenticate as yourself; YOLO then assumes a scoped role per command and runs capped to it, so it can never exceed what the command needs: read commands (`status`, `audit`) → an observer role, the deploy lifecycle (`deploy`, `build`, `run`) → the per-app deployer role, and provisioning (`sync`, `scale`) → the `yolo-*`-scoped admin role. The observer tier is **scope-aware**: a single-app read (`status`, `status:logs`) caps to a **per-app** observer role whose log-content reads are fenced to that app's log group, while an env-wide read (`status:environment`, every `audit`) caps to the env observer role. The guard is **fail-closed** — a command refuses if it can't assume its role rather than running on your full identity. See [provisioning](/guide/provisioning).
- **Admin requires MFA** — the admin role's trust requires `aws:MultiFactorAuthPresent`, so `sync` / `scale` / `permissions` prompt for a fresh 6-digit MFA code each run. Escalating to admin is therefore always an **explicit human act** an agent can't perform (it's AWS-enforced, not just a CLI prompt — a direct AssumeRole without MFA is denied). Observer and deployer carry no MFA. YOLO resolves your MFA device automatically (`iam:ListMFADevices`); set `YOLO_<ENVIRONMENT>_MFA_SERIAL` to the device ARN to skip discovery (or when it isn't permitted).
- **Grant groups** — access is granted by **group membership**, not by editing identities. YOLO provisions convention-named IAM groups (`yolo-{env}-observers`, `yolo-{env}-{app}-observers`, `yolo-{env}-{app}-deployers`, `yolo-{env}-admins`), each allowing `sts:AssumeRole` on one tier role. Add a user to a group to grant the tier, remove to revoke — managed with [`permissions`](#yolo-permissions) (or the IAM console). YOLO never creates or owns the users themselves — see [Developer Credentials](/guide/credentials) for the full onboarding flow, including each developer's local credential setup.
- **`--dangerously-skip-permissions`** — a global flag that bypasses the tier cap and runs on your full AWS identity (with a loud warning). It's the deliberate escape for **bootstrapping a fresh environment** (the first `yolo sync <env> --dangerously-skip-permissions` creates the tier roles) and for break-glass / diagnostics. Avoid it otherwise.
- **Required manifest keys** — every command except `init` checks that `name`, `region`, and `account-id` are declared, and fails fast if not.

## Commands at a glance

| Command | Purpose |
|---|---|
| [`init`](#yolo-init) | Scaffold `yolo.yml`, Dockerfile, and supporting files |
| [`configure <env>`](#yolo-configure) | Set up this machine's AWS profile and credentials for an environment |
| [`env:pull <env>`](#yolo-env-pull) | Download the app's `.env` from S3 |
| [`env:push <env>`](#yolo-env-push) | Upload the app's `.env` to S3 (with diff) |
| [`environment:manifest:pull <env>`](#yolo-environment-manifest-pull) | Download the environment manifest (`yolo-<env>.yml`) |
| [`environment:manifest:push <env>`](#yolo-environment-manifest-push) | Validate and upload the environment manifest (with diff) |
| [`environment:env:pull <env>`](#yolo-environment-env-pull) | Download the env-shared `.env` |
| [`environment:env:push <env>`](#yolo-environment-env-push) | Upload the env-shared `.env` (with diff) |
| [`build <env>`](#yolo-build) | Build and push the container image |
| [`deploy <env>`](#yolo-deploy) | Build, then roll out a zero-downtime deploy |
| [`rollback <env>`](#yolo-rollback) | Re-deploy a previously-built version from ECR, without a build |
| [`destroy <env>`](#yolo-destroy) | Permanently tear down an application and its environment, in reverse-dependency order (the reverse of `sync`) |
| [`destroy:app <env>`](#yolo-destroy-app) | Permanently tear down one app's resources (the reverse of `sync:app`) |
| [`destroy:environment <env>`](#yolo-destroy-environment) | Permanently tear down an entire environment — compute, edge and network (the reverse of `sync:environment`) |
| [`status <env>`](#yolo-status) | Live status dashboard (or a one-shot `--snapshot` / `--json` frame) |
| [`status:app <env>`](#yolo-status-app) | App-tier status (the same as `status`, under the scope namespace) |
| [`status:environment <env>`](#yolo-status-environment) | Roll up every app's status across an environment |
| [`status:logs <env>`](#yolo-status-logs) | Recent CloudWatch logs per service group |
| [`status:events <env>`](#yolo-status-events) | Recent ECS service events per group |
| [`status:alarms <env>`](#yolo-status-alarms) | The app's CloudWatch alarms and their state |
| [`status:budget <env>`](#yolo-status-budget) | Month-to-date spend against the app's declared budget |
| [`run <env>`](#yolo-run) | Open a shell / run a command in a running container |
| [`db:tunnel <env>`](#yolo-db-tunnel) | Port-forward the manifest-declared database to localhost through a running web task |
| [`scale <env> [count]`](#yolo-scale) | Adjust the web service's task count out of band |
| [`permissions <env>`](#yolo-permissions) | Grant or revoke a team member's access by editing their YOLO group membership |
| [`services <env>`](#yolo-services) | View and manage the services an environment offers |
| [`sync <env>`](#yolo-sync) | Provision all resources (account → environment → app) |
| [`sync:account <env>`](#yolo-sync-account) | Provision account-global resources |
| [`sync:environment <env>`](#yolo-sync-environment) | Provision environment-shared resources |
| [`sync:app <env>`](#yolo-sync-app) | Provision one app's resources |
| [`audit <env>`](#yolo-audit) | Health-check: tagged-resource inventory + drift check + RDS deletion-protection probe |
| [`audit:environment <env>`](#yolo-audit-environment) | Audit environment-tier resources |
| [`audit:app <env> <app>`](#yolo-audit-app) | Audit one app's resources |

---

## `yolo init`

Create the `yolo.yml` manifest in the current app root.

```bash
yolo init
```

**Arguments:** none · **Options:** none

Interactive. Prompts for the app name, the environment to add (e.g. `production`), AWS account ID, region, and (unless multi-tenant) a domain and optional S3 bucket. It then:

- Writes `yolo.yml` from the stub — the environment block keyed by the name you gave, with web [autoscaling](/guide/scaling) declared (`tasks.web.autoscaling: true`, bounds 1–5; it's a required key).
- Writes a default `Dockerfile` and `.dockerignore` (asks before overwriting existing ones).
- Creates a starter `.env.<environment>`.
- Appends `.yolo`, `.env.<environment>` (plus `.env.staging`/`.env.production`), and the env-shared working copies (`.env.environment.*`, `yolo-environment-*.yml`) to `.gitignore`.
- Offers to install the AWS Session Manager plugin (used by [`run`](#yolo-run) and [`db:tunnel`](#yolo-db-tunnel)).

This is the only command that runs without an existing manifest.

---

## `yolo configure`

Set this machine up to authenticate an environment — the developer-laptop half of [onboarding](/guide/credentials) (the account half is an IAM user plus [`permissions`](#yolo-permissions)). Runs entirely locally: it needs the manifest and a valid environment, but no AWS credentials — creating them is its job.

```bash
yolo configure <environment> [--driver=<driver>]
```

| Option | Value | Description |
|---|---|---|
| `--driver` | `1password` \| `process` | Credential source. `1password` (default) uses the bundled `yolo-credentials` helper; `process` accepts any `credential_process` command that emits credential JSON on stdout. |

Interactive; each step is checked and offered a fix rather than left to fail later:

1. **Binaries** — verifies `aws` (plus `jq` and `op` for the 1Password driver) and prints the Homebrew install lines for anything missing.
2. **Helper install** (1Password driver) — copies `yolo-credentials` from the composer package to `~/.local/bin`, so the profile survives checkout moves and `composer update` refreshes reach it on the next run.
3. **Item verification** (1Password driver) — confirms the named item exists and carries `aws_access_key_id` / `aws_secret_access_key` before anything is written.
4. **Profile write** — writes `credential_process` + the manifest's region as `[profile <name>]` in `~/.aws/config`, replacing an existing block only after confirmation. Leftover `sso_*` keys are called out by name — the CLI resolves SSO configuration ahead of `credential_process`, so remnants silently break the setup.
5. **Shadow check** — a same-named section in `~/.aws/credentials` takes precedence over `credential_process`; `configure` detects it and offers to remove it.
6. **Wire and verify** — sets `YOLO_<ENVIRONMENT>_AWS_PROFILE` in the app's local `.env`, then proves the chain with `aws sts get-caller-identity` and holds the resolved account against the manifest's `account-id`.

Profiles map to AWS **accounts**, not apps — run `configure` once per account, then reuse the profile name in each sibling app's `.env` (step 6 is the only per-app part). See [Developer Credentials](/guide/credentials).

---

## `yolo env:pull`

Download the environment file for the given environment from the app's S3 config bucket.

```bash
yolo env:pull <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

Writes `.env.<environment>` to your project root, overwriting any local copy. (For the *environment's own* files — the env manifest and the env-shared `.env` — see the [`environment:*` commands](#yolo-environment-manifest-pull).)

---

## `yolo env:push`

Upload the environment file for the given environment to the app's S3 config bucket.

```bash
yolo env:push <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

Downloads the current remote file, shows a diff of changed keys, and asks for confirmation before uploading. If no remote file exists yet, it uploads without a diff. After a successful upload it offers to **delete the local file (default: yes)** — the bucket holds the truth, and an env file left on disk is both a staleness risk and secrets sitting around for anything on the machine to read.

---

## `yolo environment:manifest:pull`

Download [the environment manifest](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml) — `yolo-environment-<environment>.yml` — from the env config bucket to your project root (gitignored).

```bash
yolo environment:manifest:pull <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

The manifest must already exist — the environment's first `sync` seeds it. The local copy keeps the bucket's name (`yolo-environment-production.yml` for production), so a pulled file can never be pushed at the wrong environment.

---

## `yolo environment:manifest:push`

Upload the environment manifest to the env config bucket.

```bash
yolo environment:manifest:push <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

Validates the local file against the manifest schema **before** anything touches the bucket — a misshapen manifest can never become the environment's declared truth — then shows a key-level diff against the remote and asks for confirmation. After uploading it offers to delete the local working copy (default: yes). Apply the pushed declaration with [`sync:environment`](#yolo-sync-environment), from any app in the environment.

Removing a [service](/guide/services#the-service-lifecycle) (`services.{name}`) is refused while a running app still uses it — the error names the app — and likewise while any running app hasn't published what it uses yet. Remove the service from each app's `yolo.yml` and deploy (or `sync:app`) it first; the push goes through once nothing is using it.

---

## `yolo environment:env:pull`

Download the env-shared `.env` — the environment-tier sibling of the app's env file, holding generated service secrets — to `.env.environment.<environment>` (gitignored).

```bash
yolo environment:env:pull <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

---

## `yolo environment:env:push`

Upload the env-shared `.env` from `.env.environment.<environment>`, with a key-level diff and confirmation. After uploading it offers to delete the local copy (default: yes).

```bash
yolo environment:env:push <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

---

## `yolo build`

Prepare a build of the application for deployment — purge the build dir, stage the app, pull the env file, run `build` hooks, generate the container entrypoint/supervisord config, then build and push the Docker image to ECR.

```bash
yolo build <environment> [--app-version=<tag>] [--no-progress]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--app-version` | string | timestamp `y.W.N.Hi` | Tag to stamp the build with. Must start with the current `year.week` prefix (e.g. `26.22`). |
| `--no-progress` | flag | off | Hide the live progress output. |

The image-building steps only run when the manifest declares `tasks`. See [Building & Deploying](/guide/building-and-deploying).

---

## `yolo deploy`

Build, push, and deploy the application — runs an in-sync check and [`build`](#yolo-build) first, then the zero-downtime rollout.

```bash
yolo deploy <environment> [--app-version=<tag>] [--group=<groups>] [--admin] [--no-progress]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--app-version` | string | timestamp `y.W.N.Hi` | Tag to stamp on the build (same rules as `build`). |
| `--group` | comma-separated | all the app runs | Service groups to roll (`web,queue,scheduler`). Defaults to every service the app runs. |
| `--admin` | flag | off | Run under the [admin tier](/guide/provisioning) (MFA-gated, prompted up front like `sync`) so a drifted environment is **reconciled inline** instead of refusing. Local/interactive only. |
| `--no-progress` | flag | off | Hide the live progress output. |

Before it builds, `deploy` runs a full [`sync --check`](#yolo-sync) (account → environment → app) and **refuses to deploy if anything has drifted** — a deploy only rolls a new task-definition revision onto the *existing* infrastructure, so it must never land on a stale target group, a changed task role, an un-provisioned listener, or a shared foundation (VPC/ALB) that no longer matches the manifest. It also fires sync's claim gate, so an app that claims an env service the environment doesn't offer is refused with a precise message. The check plans only (never writes) and runs before the build so a drift fails fast without burning one; it prints the diff.

What happens on drift depends on the tier the deploy runs under. **By default — and always in CI —** the deploy runs under the least-privilege deployer role, which *cannot* write the shared foundation that drift touches (IAM, ALB, CloudFront, autoscaling are all admin-tier). So it can't self-heal: it aborts with `Refusing to deploy — <env> has drifted from its declared state`, pointing you at [`yolo sync <env>`](#yolo-sync) (run by someone with admin) or a `--admin` rerun. The deployer role attaches the per-app `yolo-{env}-{app}-observer` read policy purely so the check can *read* the whole stack — env-level resources plus this app, with log content fenced to this app. **With `--admin`** the deploy mints the admin tier up front (MFA-prompted, exactly like `sync`), so it holds the writes to reconcile inline: it runs the real `yolo sync <env>` (you approve its plan at sync's own confirm gate), re-checks that it converged, then continues into the build in the same run — and a still-drifted environment aborts rather than looping (see [CI/CD](/guide/ci-cd#yolo-deploy-refuses-to-run-against-drift)).

Once in sync, `deploy` builds, then republishes the app's claim file (`apps/{app}.yml` in the env config bucket — see [the environment declaration](/guide/provisioning#the-environment-declaration)), pushes assets to S3, registers a new task-definition revision **for each service group** (web plus any standalone queue/scheduler), runs `deploy` hooks as a one-off task, rolls each ECS service onto its new revision, waits for the web service to go healthy (the deployment circuit breaker auto-rolls-back on failure), then UPSERTs Route 53 records. It always waits for the rollout to stabilise — there is no opt-out flag. `--group` narrows the rollout to a subset of services (the shared image is built either way); a deploy that omits `web` skips the ALB health wait, relying on the circuit breaker.

Once the rollout settles, `deploy` prints a recap — the same per-group summary table and CloudWatch dashboard link [`status`](#yolo-status) shows — so you can see what's now running and the new revision of each service.

---

## `yolo rollback`

Roll an environment back to a previously-deployed version — re-deploy an image that already exists in ECR, **without a build**.

```bash
yolo rollback <environment> [--app-version=<version>] [--group=<groups>] [--force] [--no-progress]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--app-version` | string | — | Roll back to this version non-interactively, skipping the picker. |
| `--group` | comma-separated | all the app runs | Service groups to roll (`web,queue,scheduler`). |
| `--force` / `-f` | flag | off | Skip the confirmation prompt (pair with `--app-version` for CI). |
| `--no-progress` | flag | off | Hide the live progress output. |

Run with no `--app-version` and `rollback` shows an interactive picker of the last deployments, read from ECR and listed by **app version** (parsed from the image ref), newest first. The first page holds the 10 most recent; "Show older versions →" pages back through the rest (ECR keeps the last 30). The version that's running now is marked `(current)`.

Rollback reuses the back half of [`deploy`](#yolo-deploy) — it registers a task-definition revision pinned to the chosen version, **re-runs the `deploy` hooks** against the rolled-back image, rolls each service onto it, waits for the web service to go healthy (the circuit breaker auto-rolls-back on failure), then UPSERTs Route 53 records — but runs **no build and re-pushes no assets** (the image and its asset tree already exist). The `deploy` hooks re-run because they're what makes a version live (cache rebuilds, `migrate`, …); `migrate` is forward-only, so it applies nothing new and **never reverts the schema**. Code and assets revert cleanly; the **database does not** — the confirm gate spells this out and defaults to "no": a rollback past a destructive migration can break against the old code. `--force` skips the gate.

Targets are always selected by version, never by ECS task-definition revision number — the revision integer is AWS's per-family registration counter and says nothing about which version a revision runs, and `sync`-registered revisions pin the moving `:latest` tag (so they're never offered as a rollback target).

Once the rollout settles, `rollback` prints the same recap as `deploy`.

---

## `yolo status`

The environment's live status. In a real terminal `yolo status <env>` opens a **tabbed, read-only dashboard** — it polls ECS, Application Auto Scaling, CloudWatch and SQS and redraws until you quit. `--snapshot` (and any non-interactive shell) renders a single frame instead; `--json` emits the machine-readable payload.

```bash
yolo status <environment>             # live dashboard (in a terminal)
yolo status <environment> --snapshot  # render one frame, then exit
yolo status <environment> --json      # structured payload for scripts
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--snapshot` | flag | off | Render a single frame instead of the live dashboard (piping, screenshots, CI). |
| `--json` | flag | off | Emit the status as JSON (`{app, environment, groups, queues}`) and exit — machine-readable for the `/yolo` skill and scripts. Exits non-zero if a deployment is currently failed. |

### The dashboard

Read-only and navigation-only — every mutation is its own command, so nothing runs from here. Tabs:

| Tab | What it shows |
|---|---|
| **Overview** | Per-group vitals, load, scaling, queue backlogs and any in-flight rollout — the same picture `--snapshot` renders — plus an app-wide CloudWatch alarms summary (count + any firing alarms; full list via [`status:alarms`](#yolo-status-alarms)) |
| **Web** · **Queue** · **Scheduler** | One tab per group the app runs (a combined app shows only **Web**) — that group's vitals, its CPU / memory braille charts over the last hour (plus request rate / response time for web), and a tail of its recent CloudWatch logs |
| **Deployments** | Recent deployments from ECR, the running version marked; live progress while a rollout is in flight |
| **Database** | The RDS instance/cluster behind `DB_HOST` — CPU, connections, freeable memory and latency over the last hour |
| **Cache** | The shared Valkey cache — status, endpoint, and engine CPU / memory / connections / evictions over the last hour |
| **Services** | The [service gate](/guide/services#the-service-lifecycle) — what's offered, which apps claim it, its lifecycle state, plus the Typesense cluster's live CPU / memory when offered |

A **global health bar** stays pinned on every tab — one dot per group (web / queue / scheduler), green when healthy, red when down, flipping to a rollout banner whenever a deploy is in flight, whoever triggered it. The Database, Cache and Services tabs carry a muted AWS-Console deep link to their primary resource. Navigate with `◂ ▸` / `Tab` / number keys / a tab's letter; `↑ ↓` / `PgUp PgDn` / `Home End` scroll the active tab's body; `q` quits. See the [Status Dashboard guide](/guide/status-dashboard) for the full tour.

### The snapshot frame

`--snapshot`, `--json`, or any non-interactive shell renders up to four panels instead, read live from ECS, Application Auto Scaling, CloudWatch and SQS:

- **Deployment in progress** (only when a rollout is mid-flight) — a progress bar of new-revision tasks per rolling group, its rollout state, the revision, and how long it's been running.
- **Services** — one row per group (web / queue / scheduler) with the task spec (vCPU/memory/launch type), running/desired task count, scaling bounds + policies (`1–5 auto (cpu 65%, req 1200)`, or `fixed` / `singleton`), and the deployed revision + app version.
- **Load** (last 15 min) — ECS CPU/memory per group, shown against the CPU scaling target so headroom is obvious, plus the web service's ALB request rate and response time. Each reading trails a small braille sparkline (`⢀⡠⠔⠊`) of its recent trend; the full-width charts are on each group's dashboard tab (Web / Queue / Scheduler).
- **Queue** (backlog) — the visible-message count for each SQS queue (one for a solo app; the landlord queue plus one per tenant when multi-tenant). Shown even when the queue worker is bundled into the web container rather than its own service.

Below the panels is a clickable deep link to the app's CloudWatch dashboard for the full metrics view. The snapshot returns a non-zero exit code if a deployment is currently failed — so it doubles as a lightweight health probe. `--json` emits the same state as a structured payload: each group's `load` carries both the latest reading and a `series` of its recent datapoints per metric (`load.series.cpu`, `.memory`, `.requests`, `.response`), so a consumer sees the trend, not just a lone number; `queues` lists each queue's `{label, name, backlog}`.

`status` is the **app tier** of a scope-first namespace it shares with `status:app` and `status:environment`, mirroring [`sync:*`](#yolo-sync) and [`audit:*`](#yolo-audit).

---

## `yolo status:app`

The app-tier status under the scope-first namespace — **identical to bare [`status`](#yolo-status)** (the app scope is the default). It exists so `status:app` and `status:environment` read as a pair, the way `sync:*` and `audit:*` do.

```bash
yolo status:app <environment> [--json]
```

Arguments and options as [`status`](#yolo-status).

---

## `yolo status:environment`

Roll up **every app's status** across an environment — a compact health row per app (its web service's task counts, rollout state and version), discovered from the live ECS clusters in the environment's `yolo-{env}-` namespace. The per-app detail (load, scaling, queues) is [`status`](#yolo-status) / [`status:app`](#yolo-status-app).

```bash
yolo status:environment <environment> [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--json` | flag | off | Emit the roll-up as JSON (`{environment, apps, budget}`) and exit — machine-readable for the `/yolo` skill and scripts. Exits non-zero if any app has a failed deploy. |

It renders an **App / Web / Rollout / Version** table — one row per live app — and exits non-zero if any app's deploy is currently failed, so it's usable as an environment-wide health probe. With no live apps it says so and exits zero. `--json` emits `{environment, apps[], budget}`, each app carrying `{app, exists, tasks, revision, version, rollout}`.

It also reports the **env-tier budget** — the other half of the [two-tier budget](/reference/manifest#budget): total month-to-date spend across the whole environment (every app + shared infra, via the `yolo:environment` tag) against the cap declared in the [environment manifest](/reference/manifest). `budget` is `{currency, amount, strategy, spend}`, with `spend` null until the tag is activated for cost allocation (same as [`status:budget`](#yolo-status-budget)).

---

## `yolo status:logs`

Recent CloudWatch logs per service group — the incident read surface for "what is it saying right now".

```bash
yolo status:logs <environment> [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--json` | flag | off | Emit recent logs as JSON (`{app, environment, groups}`) and exit — machine-readable for the `/yolo` skill and scripts. |

One block per group (web / queue / scheduler), each the recent log events with timestamps (or "no recent log events"). `--json` carries each group's raw `{timestamp, message}` events.

---

## `yolo status:events`

Recent ECS service events per group — the deploy / placement narrative ECS keeps (capacity, health-check, steady-state messages).

```bash
yolo status:events <environment> [--json]
```

Arguments and options as [`status:logs`](#yolo-status-logs). `--json` carries each group's `{createdAt, message}` events.

---

## `yolo status:alarms`

The app's CloudWatch alarms and their current state — the incident read surface for "is anything actually firing".

```bash
yolo status:alarms <environment> [--json]
```

Arguments and options as [`status:logs`](#yolo-status-logs), with `--json` emitting `{app, environment, alarms}` (each alarm `{name, state, reason}`).

Each alarm is shown as `OK` / `ALARM` / `?` (insufficient data) with its name and state reason. It **exits non-zero when any alarm is in `ALARM`**, so it doubles as a health probe; with no alarms for the app it says so and exits zero.

---

## `yolo status:budget`

Month-to-date spend for the app against its declared [`budget`](/reference/manifest#budget). YOLO **never enforces** a budget (it never acts) — this reports spend, the cap and the `budget.strategy`, and the [`/yolo` skill](/guide/the-yolo-skill) weights its recommendations by them.

```bash
yolo status:budget <environment> [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--json` | flag | off | Emit the budget state as JSON (`{app, environment, currency, spend, budget}`) and exit — machine-readable for the `/yolo` skill and scripts. |

Spend comes from **AWS Cost Explorer**, attributed to the app via its `yolo:app` tag. Cost Explorer is a global service (queried in us-east-1) that only attributes cost by a tag once that tag is **activated as a cost-allocation tag** in the Billing console, and its data lags ~24h — so until activation the spend shows as `—` (the command never errors on it). The line reads `$42.10 / $100.00 · 42% · strategy: balanced`, or `… · no budget set …` when the manifest declares no cap.

---

## `yolo run`

Open a shell or run a one-off command in a running container via ECS Exec.

```bash
yolo run <environment> [--command="<cmd>"] [--group=<groups>]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--command` | string | — | Run a one-off command instead of opening an interactive shell. |
| `--group` | comma-separated | `scheduler,queue,web` fallback | Task groups to target (e.g. `web,queue`). |

**Behaviour:**

- **No `--command`** → opens an interactive `/bin/sh`. In a real terminal with more than one group running (and no `--group` to narrow it), it prompts which group to shell into; otherwise it attaches to the first running task in the order `scheduler → queue → web`.
- **With `--command`** → runs the command. With `--group`, it **fans out** across every running task in each listed group. Without `--group`, it runs on the first group that has a running task.

Each group is its own ECS service when extracted, and `run` execs into the container named after the group. A bundled queue/scheduler runs inside the web container, so a `--group=queue` lookup that finds no standalone queue service simply falls through to the next group.

**Requirements:** the AWS [Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) installed locally. ECS Exec is on by default (`enable-execute-command` defaults to `true`); it must not have been disabled (`enable-execute-command: false`) on the target group in the manifest.

```bash
yolo run production
yolo run production --command="php artisan migrate:status"
yolo run production --command="php artisan queue:restart" --group=web,queue
```

---

## `yolo db:tunnel`

Port-forward the manifest-declared database to localhost through a running web task — the laptop path to a database in the [private subnet tier](/guide/provisioning#the-network), which has no public endpoint by design. (See the [Databases](/guide/databases) guide for the full picture.)

```bash
yolo db:tunnel <environment> [--port=<local-port>]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--port` | number | `13306` | The local port to listen on. |

**Behaviour:** resolves the [`database:`](/reference/manifest#database) endpoint (a bare **instance** identifier is resolved to its endpoint with a describe; declare an Aurora cluster by its full endpoint), picks a running web task, and opens an SSM port-forwarding session (`AWS-StartPortForwardingSessionToRemoteHost`) through that task to the database on `3306`. It prints the local port and streams the session until you Ctrl-C. Point your database client at `127.0.0.1:<port>` with the app's usual credentials.

Read-only convenience — nothing is created or changed. The session rides the same task-side ECS Exec plumbing `yolo run` uses (`enable-execute-command` on the service, the `ssmmessages` channels on the task role), but the caller-side permission differs: `yolo run` needs `ecs:ExecuteCommand`, while `db:tunnel` needs `ssm:StartSession` on the task target and the `AWS-StartPortForwardingSessionToRemoteHost` document. Scope that grant tightly — a port-forwarding session's host and port are chosen by the client, so `ssm:StartSession` through a task can reach anything the task can, not just the database on 3306.

**Requirements:** the AWS [Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) installed locally, a `database:` key in the manifest, and a running web task (the tunnel rides through it).

```bash
yolo db:tunnel production
yolo db:tunnel production --port=3307
```

---

## `yolo scale`

Adjust a service's capacity out of band — no build, no task-definition revision. Mirrors [`env:push`](#yolo-env-push): reads live state, shows a current → new comparison, and asks before applying.

```bash
yolo scale <environment> [count] [--web] [--min=<n>] [--max=<n>] [--queue] [--scheduler]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |
| `count` | no | Desired task count for a **fixed** (non-autoscaled) service. Prompts when omitted. |

| Option | Value | Description |
|---|---|---|
| `--web` | flag | Target the web service (the default). |
| `--queue` | flag | Target the standalone queue service. Autoscaling-managed by default — takes `--min`/`--max` (min may be `0`); a fixed (`autoscaling: false`) queue takes a count instead. |
| `--scheduler` | flag | Always errors — the scheduler is a singleton and can't be scaled. |
| `--min` / `--max` | int | Autoscaling bounds — the autoscaled form. |

There are two forms, picked by what you pass:

- **Autoscaled** — `--min`/`--max` set the bounds. The values are written back to the manifest (surgically — comments and formatting are preserved) under [`tasks.{group}.autoscaling.min/max`](/reference/manifest#tasks-web-autoscaling) for both web and queue. The scalable target is then registered, so the **manifest stays the source of truth** and the next sync reconciles to the same values. A desired count is never set under autoscaling (the policies would override it).
- **Fixed** — a positional `count` sets the ECS desired count directly (`UpdateService`), for a service with `autoscaling: false` (web or queue). An autoscaling service (the default) errors and points you to `--min/--max`.

Lowering a live bound is guarded the same as [reducing capacity](/guide/scaling#reducing-capacity-is-guarded) — an explicit confirm defaulting to no.

```bash
yolo scale production --web --min=3 --max=10    # web autoscaled bounds (writes the manifest)
yolo scale production --web 3                    # web fixed desired count
yolo scale production --queue --min=0 --max=20   # queue bounds — min 0 = scale to zero
yolo scale production                            # prompt for a fixed count
```

**Reducing capacity** (a bound below the live value) is confirm-gated and defaults to *no*. See [Scaling](/guide/scaling).

---

## `yolo permissions`

Grant or revoke a team member's access by editing which YOLO [grant groups](#conventions) they belong to — membership is the entire access lever. Runs in an app's directory like `deploy`/`scale`: it offers the env-wide tiers plus this app's per-app tiers.

```bash
yolo permissions <environment>
```

Interactive: pick an IAM user, then a checkbox list pre-ticked with their current grants —

| Tier offered | Group | Grants |
|---|---|---|
| Observer — entire environment | `yolo-{env}-observers` | read every app in the environment |
| Observer — this app only | `yolo-{env}-{app}-observers` | read this app (log content fenced to its log group) |
| Deployer — this app | `yolo-{env}-{app}-deployers` | deploy this app (only offered when the app has a deployer role) |
| Admin — entire environment | `yolo-{env}-admins` | `sync` / `scale` / manage access |

Toggling and confirming applies the membership diff — and only ever touches these YOLO groups, never a user's other group memberships. Only groups that have actually been [synced](#yolo-sync) are offered (you can't grant a tier that isn't provisioned). It runs under the **admin** tier, whose policy can manage `yolo-*` group membership, so a member of `yolo-{env}-admins` can grant access to others. To grant deploy on a different app, run it in that app's directory. See [provisioning](/guide/provisioning), and [Developer Credentials](/guide/credentials) for the end-to-end onboarding flow this fits into (IAM user, MFA, local credential setup).

---

## `yolo services`

View and manage [services](/guide/services#the-service-lifecycle) for an app and its environment. The interactive view is app-centric — a `Service · Description · Status` table where **Status** is whether *this app* uses each service — with enable / disable per service.

```bash
yolo services <environment> [--json] [--add=<service>] [--set key=value] [--remove=<service>]
```

| Option | Value | Description |
|---|---|---|
| `--json` | flag | Print the service state as JSON and exit — no prompts (for agents/CI). |
| `--add` | service | Offer a service non-interactively (pair with `--set`). |
| `--set` | `key=value` | An offer field for `--add`, repeatable (e.g. `--set version=30.2 --set nodes=3`). |
| `--remove` | service | Withdraw a service offer non-interactively. |

Run with no options for the interactive picker (`Cancel` is the last option). The table lists every service with a one-line description and whether **this app** has it enabled; selecting one lets you:

- **Enable / Disable for this app** — write (or remove) the service in this app's `yolo.yml` `services` claim. The write is surgical (it preserves your manifest's comments and formatting). For an app-side service (`mediaconvert`, `rekognition`) that's the whole change, and it offers to run [`sync:app`](#yolo-run) right then.
- For an **env-backed** service (`typesense`, `ivs`), enabling also walks you through its **environment offer** — e.g. Typesense's version / nodes / CPU / RAM. Constrained fields are **selects of known values** (the Typesense version picks from the releases YOLO provisions, newest the default; node count from the quorum-valid set), and free-form sizing (CPU / RAM) is typed with sensible defaults. The offer is written to a **local copy** of the [environment manifest](/reference/manifest) (not pushed straight to the bucket), the command **warns you of the cost and blast-radius implications**, and then it tells you — and offers — to run `environment:manifest:push <env>` followed by `sync <env>` to apply.

The `--add` / `--set` / `--remove` flags drive the **environment offer** non-interactively (for agents/CI), uploading the env manifest directly. A service still can't be withdrawn while a running app claims it (the same guard as [`environment:manifest:push`](#yolo-environment-manifest-push)).

```bash
yolo services production                                          # interactive
yolo services production --json                                   # read state
yolo services production --add=typesense --set version=30.2 --set nodes=3
yolo services production --remove=typesense
```

---

## `yolo sync`

Sync **all** resources for the given environment, orchestrating the three scopes in dependency order: account → environment → app.

```bash
yolo sync <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

<a id="sync-options"></a>

| Option | Short | Value | Description |
|---|---|---|---|
| `--check` | | flag | Plan only and exit non-zero if the environment has drifted — never applies. Intended as a CI gate. |
| `--force` | `-f` | flag | Skip the confirmation prompt. |
| `--no-progress` | | flag | Hide the live progress output. |
| `--tenant` | | string | Limit per-tenant steps to a single tenant id. |

`sync` is always **approve-before-apply**: it runs a read-only plan pass, prints the full diff (Will create / Pending changes / Skipping), then asks you to confirm before writing anything — so you always see exactly what will change first, and declining (or Ctrl-C) is the preview. `--force` skips that confirm for unattended applies.

`--check` is the machine-readable form of that plan pass: it prints the same diff, never applies, and returns a non-zero exit code when there are pending changes (and `0` when the environment is already in sync). Run `yolo sync <env> --check` in CI to fail a pipeline on drifted or unsynced infrastructure. A non-zero exit also covers a plan that errored (bad credentials, AWS API failure, invalid manifest) — either way, CI should stop and a human should look.

The plan pass is read-only, so it fans out across up to 8 worker processes and renders the same plan in a fraction of the time; the apply pass always runs sequentially, in declaration order. Forking needs the `pcntl` extension (standard on macOS/Linux CLI builds) — without it, or with `YOLO_PLAN_SEQUENTIAL=1` set in the environment, the plan runs in-process instead, with identical output.

These four options are shared by every `sync` command below. See [Provisioning](/guide/provisioning) for the plan/confirm/apply flow.

---

## `yolo sync:account`

Sync the account-global resources (shared across every environment) — the GitHub OIDC identity provider.

```bash
yolo sync:account <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **account**.

---

## `yolo sync:environment`

Sync the environment-shared (environment-tier) resources — VPC, the public and private subnet tiers with their route tables ([the network](/guide/provisioning#the-network)), the internet gateway, the private-only RDS DB subnet group, any [declared VPC peering](/guide/databases) (the env manifest `peering` list: connections created/accepted, routes both ways, DNS resolution last — the whole bridge torn down when an entry is removed), the load balancer security group, the env config bucket holding [the environment's declaration](/guide/provisioning#the-environment-declaration) (env manifest + env-shared `.env`, the manifest seeded once on first sync), the env-backed services governed by [the service lifecycle](/guide/services#the-service-lifecycle) (the IVS event-logging pipeline and the [Typesense search cluster](/guide/services#typesense-the-environment-s-search-cluster) — each provisioned while the env manifest declares it, planned as a `WOULD DELETE` teardown once the entry is removed, and flagged as idle if declared but unused), the ALB and its `:80` listener, the SNS alarm topic, the shared ECS execution IAM role, the env-shared `yolo-{env}-observer` read-only policy (the drift-check inspection surface every app's deployer role attaches — see [CI/CD](/guide/ci-cd#what-yolo-sync-provisions-for-ci)), the `yolo-{env}-observer-role` an operator or agent assumes for safe **read-only** inspection (it carries that policy — point a `*-readonly` profile at it), the env-wide [grant groups](#conventions) (`yolo-{env}-observers`, `yolo-{env}-admins`) whose membership grants the read / admin tier, and the [WAF web ACL](/guide/provisioning#web-application-firewall) (with its allow/block IP sets) fronting the ALB.

```bash
yolo sync:environment <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **environment**. These resources are shared by every app in the environment; apps attach to them but never mutate them.

---

## `yolo sync:app`

Sync a single application's resources for the given environment — S3 buckets, the app's published claim file (`apps/{app}.yml` in the env config bucket), app IAM (deployer role/policy, the per-app **observer** role/policy whose log-content reads are fenced to this app's log group, the per-app ECS task role plus any [`task-role-policies`](/reference/manifest#task-role-policies), and the MediaConvert role when the app uses the [`mediaconvert` service](/reference/manifest#services) — torn down again on the sync after the app stops using it), the per-app [grant groups](#conventions) (`yolo-{env}-{app}-observers` always; `yolo-{env}-{app}-deployers` when the app has a deployer role) whose membership grants read / deploy on this app, ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, SQS queues, a CloudWatch dashboard, target-tracking autoscaling (when configured), and — for a solo app — its hosted zone and ACM certificate. For web apps it also provisions the shared [Valkey cache](/guide/provisioning#cache-and-sessions) (`cache.store`, default-on); sessions ride the same cluster by default ([`session.driver: redis`](/guide/provisioning#cache-and-sessions)), so they need no resources of their own.

```bash
yolo sync:app <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **app**.

The step set is mode-aware: a multi-tenant app fans out landlord + per-tenant queues (and skips the solo hosted zone/cert); a solo app gets the apex zone + certificate. Web/CDN steps only run when `tasks.web` is declared. Use `--tenant=<id>` to narrow per-tenant steps to one tenant.

Some environment-tier resources are bootstrapped here by exception — the RDS security group (because its real purpose is this app's task-SG ingress), the HTTPS `:443` listener (because its creation needs this app's certificate), and the shared Valkey cache when `cache.store` is set (its security group needs this app's task SG to authorise). All are created-if-missing and never mutated, so the environment tier remains their single writer.

A per-app **CloudWatch dashboard** (`yolo-<env>-<app>-dashboard`) is generated last, so every resource it charts already exists. It groups the web ECS service with the ALB (target health, requests, latency, slow-request bands, error counts and a 5xx error-rate SLO) above its compute (CPU/memory/tasks/network), then the queue directly below — one **Queue** section folding the worker's own compute (when it's extracted to its own service) in with its SQS depth/throughput/oldest-age backlog — then any extracted scheduler, the WAF posture (including a service's own rules, like the Typesense search rate limit), the asset CloudFront distribution (requests, errors and cache hit rate — YOLO turns on the distribution's additional-metrics subscription so the hit rate has data), the S3 buckets, any consumed services (MediaConvert jobs, Rekognition requests) and the app's logs — plus an RDS panel sourced from the manifest [`database:`](/reference/manifest#database) key, omitted when it isn't set (CPU, connections, memory, read/write latency, and per-statement throughput on Aurora or read/write IOPS on a plain instance). The RDS panel reads the manifest, never the app's secret `.env`. It's a read-only convenience: CloudWatch dashboards can't carry tags, so it doesn't appear in `yolo audit`.

When a [`tasks.web.autoscaling`](/reference/manifest#tasks-web-autoscaling) block is present, `sync:app` also registers the **scalable target** and its **target-tracking policies** (request concurrency by default, derived from task memory, plus CPU as a safety net), right after the ECS service. App Auto Scaling targets aren't taggable either, so they're invisible to `yolo audit` too. If autoscaling is enabled on a task that also runs the scheduler, the sync plan lists an advisory under its **Warnings** section — see [Scaling](/guide/scaling). Scaling is web-only and inert without the manifest block.

---

## `yolo destroy`

Permanently tear an application **and its environment** down in one pass — the reverse of [`sync`](#sync), which builds account → environment → app. destroy runs **app → environment → account** in reverse-dependency order, behind a single **plan → confirm → apply** gate, so nothing is removed while something still references it. Everything that belongs to the environment goes — gated only on whether anything else still uses it.

```bash
yolo destroy <environment> [--check] [--force] [--no-progress]
```

Arguments and options as [`sync`](#sync-options). Scope: **app → environment → account**. Admin-tier.

What it tears down, each scope self-gating:

- **app** — this app's resources ([`destroy:app`](#yolo-destroy-app)).
- **environment** — the compute/edge tier ([`destroy:environment`](#yolo-destroy-environment) Tier A) **and the network shell** (VPC, subnets, route table, internet gateway, RDS SG + subnet group) — *unless a database is attached to the VPC*, which keeps the shell standing (YOLO never deletes a database it doesn't own, and a live DB pins the whole network; the blocking instance is named in the summary).
- **account** — the account-shared GitHub OIDC provider, reclaimed **only when no other environment remains** (no resource tagged `yolo:environment=<other>`). It fails safe: if that can't be determined, the provider is kept, never deleted on a guess.

**Never deleted.** The database and the bring-your-own [app data bucket](/reference/manifest#bucket) are off-limits — not by configuration, but structurally: the data bucket isn't a deletable resource, and no destructive RDS call exists anywhere in YOLO (both enforced by tests). The confirmation names them so you can see they're safe.

**Guarded.** The app must be a shape `destroy:app` supports, and no *other* app may still claim the environment — this app is torn down in the same run, so it's excused, but any sibling must be destroyed first. The yolo.yml environment block is stripped as the very last step, after everything that still needs the manifest's account/region to resolve.

**The confirmation.** A destroy is irreversible, so the gate is loud: a red banner, a **PROTECTED** callout naming the database + app data bucket, and a prompt to **type the environment name** (no fat-fingerable y/N). `--force` / non-interactive skips it, as with sync. Anything deliberately kept — the network shell while a database is attached, the OIDC provider while other environments exist — is listed back at the end of the run with the reason, so it's never a silent omission.

---

## `yolo destroy:app`

Permanently tear one application's resources down in the given environment — the reverse of [`sync:app`](#yolo-sync-app). It uses the same **plan → confirm → apply** flow: a plan pass lists every resource that **would delete**, the confirm gate guards the irreversible apply (declining is the preview), and `--check` is the non-interactive plan-only form for CI. The apply pass deletes in reverse dependency order — CloudFront → autoscaling → ECS services → cluster → listener rules → target group → task security group → app IAM → TLS cert detach → SQS → hosted-zone records → buckets → ECR — so a resource is never deleted while something still references it.

```bash
yolo destroy:app <environment> [--check] [--force] [--no-progress]
```

Arguments and options as [`sync`](#sync-options). Scope: **app**. Admin-tier.

**App-scoped only — shared and stateful infrastructure is deliberately preserved:**

- The **app data bucket** (the BYO [`bucket`](/reference/manifest#bucket)) holds user data and is never deleted — it isn't even YOLO-tagged. The regenerable asset and config buckets *are* emptied and removed.
- **RDS is never touched** (YOLO owns the security group, not the database) — destroy:app *revokes this app's 3306 ingress rule* from the shared RDS security group, never the group itself.
- **The hosted zone is never deleted** — it's domain-level infrastructure (the registrar's NS delegation points at it, and the domain's email/verification DNS and any sibling environment's records live in it). destroy:app *withdraws only the A/AAAA records it added* and leaves the zone — and everything else in it — standing.
- **The ACM/TLS certificate is never deleted** — like the hosted zone it's domain-level: ACM addresses a certificate by domain name only, so a sibling environment serving the same domain may hold one too and a domain-keyed lookup can't tell them apart. destroy:app *detaches the certificate from this environment's `:443` listener* (withdrawing the app's SNI slice) but leaves the certificate itself standing (certs cost nothing to keep). A default-cert that can't be detached app-side is tolerated and freed when the environment's listener is torn down.
- The shared **`:443` listener** and the **Valkey cache** stay for the environment's other apps — destroy:app removes only this app's listener rule, detaches its SNI certificate association, and revokes this app's cache ingress rule.
- **Env-service per-app resources are torn down** — for an app consuming a service, destroy:app reverses its per-app half: it revokes this app's Typesense node-SG ingress, deletes the per-app MediaConvert role, and removes the app's per-app env file (which also held its minted Typesense keys). The env-shared service stack itself (the search cluster, the WAF, …) is environment-scoped and left standing.
- **Environment- and account-scoped** resources (VPC, subnets, ALB, OIDC provider, …) are out of scope — tear the whole environment down with [`destroy:environment`](#yolo-destroy-environment).

**It refuses rather than partially tearing down.** To guarantee a teardown can never orphan resources (which [`yolo audit`](#yolo-audit) would then flag), destroy:app refuses — with a clear message — app shapes whose teardown isn't fully modelled yet: **multi-tenant** apps, **headless** apps (no domain), and apps with **no web task**. (Consuming an env service is no longer a refusal — destroy:app reverses each service's per-app resources; only a service that adds per-app resources with no teardown modelled would refuse, which none do today.)

**The confirmation.** Like the other destroy commands, the gate is a red banner with a **PROTECTED** callout (the database + app data bucket) and a prompt to **type the environment name** before the irreversible apply. `--force` / non-interactive skips it.

---

## `yolo destroy:environment`

Permanently tear an **entire environment** down — the reverse of [`sync:environment`](#yolo-sync-environment), behind the same **plan → confirm → apply** flow (`--check` is the CI plan-only form). The apply pass deletes in reverse dependency order: the env-backed **service stacks** (Typesense / IVS) first, then the **WAF** off the load balancer, the `:443`/`:80` **listeners** + the **load balancer** + its security group, the shared **Valkey cache** (replication group + its subnet/parameter groups + security group), the **SNS alarm topic**, the shared **ECS execution role** and the **observer/admin** IAM tiers, the **env buckets**, the **network shell** (RDS subnet group + SG, the public subnets, the route table, the internet gateway, and the VPC last), and finally — once every AWS resource is gone — the environment's block in the local `yolo.yml`.

```bash
yolo destroy:environment <environment> [--check] [--force] [--no-progress]
```

Arguments and options as [`sync`](#sync-options). Scope: **environment**. Admin-tier.

**It tears the whole environment down — compute/edge (Tier A) and the network shell (Tier B):**

- **The network shell is reclaimed automatically** (VPC, subnets, route table, internet gateway, RDS security group + subnet group), after Tier A — *unless a database is attached to the VPC*. A surviving RDS instance lives in the VPC's private subnets using that security group and subnet group, and AWS pins all of it (you can't delete an in-use security group, an in-use subnet, or a VPC with a live ENI), so a live database keeps the whole shell standing; it's named in the refusal summary. Snapshot and drop the database out-of-band, then re-run to reclaim the network.
- **RDS is never touched** — YOLO owns the security group, never the database.
- **The env buckets go with the environment.** The env config bucket (the env manifest + env-shared `.env`) and the env logs bucket (ALB access logs) are regeneratable infrastructure config, emptied and deleted as part of the teardown. The bring-your-own app data bucket is never touched (it isn't even a deletable resource).
- The env-backed **service stacks** come down even though the env manifest still declares them: the command forces [the service lifecycle](/guide/services#the-service-lifecycle) to *teardown* for the duration of the run, reusing the same per-service teardown the manifest-removal path uses.

**Guarded — it refuses while any app still claims the environment.** If any app has a published claim file or running tasks, destroy:environment names them and stops: tear each down with [`destroy:app`](#yolo-destroy-app) first, so the shared resources never go out from under a live app.

**Runs standalone — it doesn't need the environment in `yolo.yml`.** Under the normal flow [`destroy:app`](#yolo-destroy-app) has already removed the environment's block from the manifest by the time you tear the environment itself down, so destroy:environment reconstructs the environment's config from the live account rather than the file: the **account-id** from the AWS credential (STS), the **region** from the AWS profile's config (or an explicit `YOLO_<ENV>_AWS_REGION`), and the **domain + services** from the published [environment manifest](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml) in S3. Anything it can't determine — the profile, the region — it prompts for rather than failing. When the environment *is* still declared in `yolo.yml`, that block is used for the teardown and then **dropped as the final step** — the same strip [`destroy:app`](#yolo-destroy-app) does — so a standalone teardown never leaves a dead environment declaration behind. (A run whose block was already removed simply skips that step.)

**The confirmation.** Same loud gate as [`destroy`](#yolo-destroy): a red banner, a **PROTECTED** callout naming the database + app data bucket, and a prompt to **type the environment name** before the irreversible apply. On the standalone path (above) it adds a **type-the-account-id** confirm up front — the which-account check that stands in for the manifest's account-id↔profile match when there's no local block — and the admin tier's **MFA** gates minting its credentials, so an environment-wide teardown takes account-id + MFA + env-name. `--force` / non-interactive skips the typed confirms (CI).

---

## `yolo audit`

Health-check an environment. Bare `audit` does three things: the **tag inventory** (every YOLO-tagged resource, account → environment → app, flagging anything not accounted for), a **whole-stack drift check** (the same `sync --check` plan the deploy gate runs), and an **RDS deletion-protection / topology probe**. Read-only — it never writes, and runs under the env observer tier.

```bash
yolo audit <environment> [--unexpected] [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Description |
|---|---|---|
| `--unexpected` | flag | Only show unexpected resources — anything not accounted for by YOLO. (Filters the inventory table; the drift and RDS probes still run.) |
| `--json` | flag | Emit the audit as JSON (`{environment, liveApps, okCount, unexpectedCount, resources, health, findings, healthy}`) and exit — machine-readable for the `/yolo` skill and scripts. The `health` block carries the RDS snapshot and drift verdict; `findings` lists the same errors/warnings the table prints. Honours the same scope and `--unexpected` filtering as the table. |

**The tag inventory** queries the Resource Groups Tagging API for everything tagged `yolo:environment=<env>` and classifies each resource as **`ok`** or **`unexpected`**, with a **Reason** explaining each unexpected row — `no ownership tag`, `service no longer provisioned`, or `app cluster gone` (see [Provisioning › Auditing](/guide/provisioning#auditing-what-s-deployed)). Results are grouped by scope, unexpected-first within a scope, with clickable AWS Console links where the terminal supports them.

**The drift check** runs the whole-stack `sync --check` plan (account → environment → app) in-process, reusing the deploy gate's machinery verbatim. It plans only — never writes — and inherits the audit's read-only cap, so there's no escalation and no MFA prompt. A drifted environment surfaces the sync plan (so you see *which* resources drifted) and points you at `yolo sync <env>` to reconcile. The env-backed-service reconcilers a read tier can't inspect — the env-shared admin-owned state and an app's Observer-fenced per-app Typesense key alike — are skipped, exactly as in the deploy gate — `yolo sync` is their drift check.

**The RDS probe** looks up the database the manifest [`database:`](/reference/manifest#database) key declares — instance or Aurora cluster, MySQL or Aurora — and reports its **deletion protection**, engine/version, size and (for Aurora) the writer + reader members. It reads the database directly by identifier (RDS isn't YOLO-tagged, so it isn't in the inventory). When no `database:` is declared the probe is skipped.

It also classifies the database's **network posture** — which VPC and subnet group it actually sits in, and whether it's reachable:

- **managed** — the end-state: the env VPC, the [private DB subnet group](/guide/provisioning#the-network), the YOLO RDS security group.
- **externally managed** — a different VPC (or hand-wired networking): valid, informational. The transitional peered pattern while migrating a database into the yolo VPC lands here.
- **EXPOSED** — `PubliclyAccessible` is on: the database has an internet-facing endpoint regardless of VPC. A warning.

The posture is **audit-only, never sync drift** — the deploy gate runs `sync --check`, and an externally-hosted database must not block deploys. Each cross-service read degrades to *unknown* when the tier can't make it; an unknown fact is never a warning.

**Exit code & severity.** Bare `audit` is a green/red health gate: it exits **non-zero on any error** and `0` otherwise. **Errors** (fail the run): unexpected resources, drift, and a database with **deletion protection off**. **Warnings** (never fail the run): a database that can't be read (it doesn't exist, or the tier was denied) — we can't confirm protection is on, only that it isn't confirmed off; a **publicly accessible** database; and no attached security group allowing `3306` from the app's task security group (the app may not be able to reach it). Findings render in one block at the end, warnings then errors.

> The RDS deletion-protection probe and the drift check are **bare `audit` only**. The scoped verbs below (`audit:environment`, `audit:app`) stay focused inventory tools — they classify tagged resources and flag unexpected ones (which still exit non-zero), but run no drift or RDS probe.

---

## `yolo audit:environment`

Audit only the environment-tier resources for the given environment.

```bash
yolo audit:environment <environment> [--unexpected]
```

Arguments and options as [`audit`](#yolo-audit). Filters to environment-scope rows. Environment-scope resources never carry `yolo:app`, but they can still be `unexpected` (an untagged resource in the namespace, or a leftover of a service YOLO no longer provisions), so `--unexpected` is meaningful here.

---

## `yolo audit:app`

Audit a single app's resources for the given environment.

```bash
yolo audit:app <environment> <app> [--unexpected]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |
| `app` | yes | The app name (matches the resource's `yolo:app` tag) |

| Option | Value | Description |
|---|---|---|
| `--unexpected` | flag | Only show unexpected resources for this app. |

Filters the environment-wide report to rows whose `yolo:app` tag matches `<app>`, so only `ok` and `unexpected` rows for that app appear (a resource with no `yolo:app` marker never shows here).
