# Command Reference

Every YOLO command, with its arguments and options. Run `vendor/bin/yolo` with no arguments to list them, or `vendor/bin/yolo <command> --help` for Symfony's generated usage.

## Conventions

- **`<environment>`** — almost every command takes a required `environment` argument naming a key under `environments` in your `yolo.yml` (e.g. `production`, `staging`).
- **AWS authentication** — outside CI, YOLO reads a named AWS profile from `YOLO_<ENVIRONMENT>_AWS_PROFILE` in your local `.env`. Before any AWS call it verifies (via STS) that the profile resolves to the `account-id` declared in the manifest. The `default` profile is rejected. In CI it falls back to the AWS SDK default credential chain (GitHub OIDC, SSO).
- **Permission tiers** — you authenticate as yourself; YOLO then assumes a scoped role per command and runs capped to it, so it can never exceed what the command needs: read commands (`status`, `audit`) → an observer role, the deploy lifecycle (`deploy`, `build`, `run`) → the per-app deployer role, and provisioning (`sync`, `scale`) → the `yolo-*`-scoped admin role. The observer tier is **scope-aware**: a single-app read (`status`, `status:logs`) caps to a **per-app** observer role whose log-content reads are fenced to that app's log group, while an env-wide read (`status:environment`, every `audit`) caps to the env observer role. The guard is **fail-closed** — a command refuses if it can't assume its role rather than running on your full identity. See [provisioning](/guide/provisioning).
- **Admin requires MFA** — the admin role's trust requires `aws:MultiFactorAuthPresent`, so `sync` / `scale` / `permissions` prompt for a fresh 6-digit MFA code each run. Escalating to admin is therefore always an **explicit human act** an agent can't perform (it's AWS-enforced, not just a CLI prompt — a direct AssumeRole without MFA is denied). Observer and deployer carry no MFA. YOLO resolves your MFA device automatically (`iam:ListMFADevices`); set `YOLO_<ENVIRONMENT>_MFA_SERIAL` to the device ARN to skip discovery (or when it isn't permitted).
- **Grant groups** — access is granted by **group membership**, not by editing identities. YOLO provisions convention-named IAM groups (`yolo-{env}-observers`, `yolo-{env}-{app}-observers`, `yolo-{env}-{app}-deployers`, `yolo-{env}-admins`), each allowing `sts:AssumeRole` on one tier role. Add a user to a group to grant the tier, remove to revoke — managed with [`permissions`](#yolo-permissions) (or the IAM console). YOLO never creates or owns the users themselves.
- **`--dangerously-skip-permissions`** — a global flag that bypasses the tier cap and runs on your full AWS identity (with a loud warning). It's the deliberate escape for **bootstrapping a fresh environment** (the first `yolo sync <env> --dangerously-skip-permissions` creates the tier roles) and for break-glass / diagnostics. Avoid it otherwise.
- **Required manifest keys** — every command except `init` checks that `name`, `region`, and `account-id` are declared, and fails fast if not.

## Commands at a glance

| Command | Purpose |
|---|---|
| [`init`](#yolo-init) | Scaffold `yolo.yml`, Dockerfile, and supporting files |
| [`env:pull <env>`](#yolo-env-pull) | Download the app's `.env` from S3 |
| [`env:push <env>`](#yolo-env-push) | Upload the app's `.env` to S3 (with diff) |
| [`environment:manifest:pull <env>`](#yolo-environment-manifest-pull) | Download the environment manifest (`yolo-<env>.yml`) |
| [`environment:manifest:push <env>`](#yolo-environment-manifest-push) | Validate and upload the environment manifest (with diff) |
| [`environment:env:pull <env>`](#yolo-environment-env-pull) | Download the env-shared `.env` |
| [`environment:env:push <env>`](#yolo-environment-env-push) | Upload the env-shared `.env` (with diff) |
| [`build <env>`](#yolo-build) | Build and push the container image |
| [`deploy <env>`](#yolo-deploy) | Build, then roll out a zero-downtime deploy |
| [`rollback <env>`](#yolo-rollback) | Re-deploy a previously-built version from ECR, without a build |
| [`status <env>`](#yolo-status) | Snapshot of one app's services, load, scaling and any in-progress deploy |
| [`status:app <env>`](#yolo-status-app) | App-tier status (the same as `status`, under the scope namespace) |
| [`status:environment <env>`](#yolo-status-environment) | Roll up every app's status across an environment |
| [`status:logs <env>`](#yolo-status-logs) | Recent CloudWatch logs per service group |
| [`status:events <env>`](#yolo-status-events) | Recent ECS service events per group |
| [`status:alarms <env>`](#yolo-status-alarms) | The app's CloudWatch alarms and their state |
| [`status:budget <env>`](#yolo-status-budget) | Month-to-date spend against the app's declared budget |
| [`run <env>`](#yolo-run) | Open a shell / run a command in a running container |
| [`scale <env> [count]`](#yolo-scale) | Adjust the web service's task count out of band |
| [`permissions <env>`](#yolo-permissions) | Grant or revoke a team member's access by editing their YOLO group membership |
| [`services <env>`](#yolo-services) | View and manage the services an environment offers |
| [`tui [env]`](#yolo-tui) | Open the interactive dashboard |
| [`sync <env>`](#yolo-sync) | Provision all resources (account → environment → app) |
| [`sync:account <env>`](#yolo-sync-account) | Provision account-global resources |
| [`sync:environment <env>`](#yolo-sync-environment) | Provision environment-shared resources |
| [`sync:app <env>`](#yolo-sync-app) | Provision one app's resources |
| [`audit <env>`](#yolo-audit) | Audit tagged resources and flag anything unexpected |
| [`audit:environment <env>`](#yolo-audit-environment) | Audit environment-tier resources |
| [`audit:app <env> <app>`](#yolo-audit-app) | Audit one app's resources |

---

## `yolo init`

Create the `yolo.yml` manifest in the current app root.

```bash
yolo init
```

**Arguments:** none · **Options:** none

Interactive. Prompts for the app name, AWS account ID, region, and (unless multi-tenant) a domain and optional S3 bucket. It then:

- Writes `yolo.yml` from the stub — with web [autoscaling](/guide/scaling) on by default (`tasks.web.autoscaling: true`, bounds 1–4).
- Writes a default `Dockerfile` and `.dockerignore` (asks before overwriting existing ones).
- Creates a starter `.env.production`.
- Appends `.yolo`, `.env.staging`, `.env.production`, and the env-shared working copies (`.env.environment.*`, `yolo-environment-*.yml`) to `.gitignore`.
- Offers to install the AWS Session Manager plugin (used by [`run`](#yolo-run)).

This is the only command that runs without an existing manifest.

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

Removing a [service](/guide/provisioning#the-service-lifecycle) (`services.{name}`) is refused while a running app still uses it — the error names the app — and likewise while any running app hasn't published what it uses yet. Remove the service from each app's `yolo.yml` and deploy (or `sync:app`) it first; the push goes through once nothing is using it.

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
yolo deploy <environment> [--app-version=<tag>] [--group=<groups>] [--no-progress]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--app-version` | string | timestamp `y.W.N.Hi` | Tag to stamp on the build (same rules as `build`). |
| `--group` | comma-separated | all the app runs | Service groups to roll (`web,queue,scheduler`). Defaults to every service the app runs. |
| `--no-progress` | flag | off | Hide the live progress output. |

Before it builds, `deploy` runs a full [`sync --check`](#yolo-sync) (account → environment → app) and **refuses to deploy if anything has drifted** — a deploy only rolls a new task-definition revision onto the *existing* infrastructure, so it must never land on a stale target group, a changed task role, an un-provisioned listener, or a shared foundation (VPC/ALB) that no longer matches the manifest. It also fires sync's claim gate, so an app that claims an env service the environment doesn't offer is refused with a precise message. The check plans only (never writes) and runs before the build so a drift fails fast without burning one; it prints the diff, then aborts with `Refusing to deploy — <env> is not in sync`. Reconcile with [`yolo sync <env>`](#yolo-sync) and redeploy. In CI this runs under the deployer role, which attaches the env's `yolo-{env}-observer` read policy for exactly this (see [CI/CD](/guide/ci-cd#yolo-deploy-refuses-to-run-against-drift)).

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

Show a snapshot of the app's running state for an environment — what each service group is running, its current load, scaling configuration, and any deploy in progress.

```bash
yolo status <environment> [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--json` | flag | off | Emit the status as JSON (`{app, environment, groups, queues}`) and exit — machine-readable for the `/yolo` skill and scripts. Exits non-zero if a deployment is currently failed. |

It renders up to four panels, read live from ECS, Application Auto Scaling, CloudWatch and SQS:

- **Deployment in progress** (only when a rollout is mid-flight) — a progress bar of new-revision tasks per rolling group, its rollout state, the revision, and how long it's been running.
- **Services** — one row per group (web / queue / scheduler) with the task spec (vCPU/memory/launch type), running/desired task count, scaling bounds + policies (`1–4 auto (cpu 65%, req 1200)`, or `fixed` / `singleton`), and the deployed revision + app version.
- **Load** (last 5 min) — ECS CPU/memory per group, shown against the CPU scaling target so headroom is obvious, plus the web service's ALB request rate and response time. Each reading trails a small `▁▂▃▅▇` sparkline of its recent trend.
- **Queue** (backlog) — the visible-message count for each SQS queue (one for a solo app; the landlord queue plus one per tenant when multi-tenant). Shown even when the queue worker is bundled into the web container rather than its own service.

Below the panels is a clickable deep link to the app's CloudWatch dashboard for the full metrics view.

It **renders once and exits**, returning a non-zero exit code if a deployment is currently failed — so it doubles as a lightweight health probe. For the live, polling cockpit (it redraws and picks up deploys as they start), open [`yolo tui`](/guide/tui); its Status tab is this same picture, kept fresh. `--json` emits the same state as a structured payload rather than the panels — the machine-readable contract the `/yolo` skill (and any script) consumes. Each group's `load` carries both the latest reading and a `series` of its recent datapoints per metric (`load.series.cpu`, `.memory`, `.requests`, `.response`), so a consumer sees the trend, not just a lone number; `queues` lists each queue's `{label, name, backlog}`.

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

- **No `--command`** → opens an interactive `/bin/sh` in the first running task (searched in the order `scheduler → queue → web`).
- **With `--command`** → runs the command. With `--group`, it **fans out** across every running task in each listed group. Without `--group`, it runs on the first group that has a running task.

Each group is its own ECS service when extracted, and `run` execs into the container named after the group. A bundled queue/scheduler runs inside the web container, so a `--group=queue` lookup that finds no standalone queue service simply falls through to the next group.

**Requirements:** the AWS [Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) installed locally, and `enable-execute-command: true` on the target group in the manifest.

```bash
yolo run production
yolo run production --command="php artisan migrate:status"
yolo run production --command="php artisan queue:restart" --group=web,queue
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
| `--queue` | flag | Target the standalone queue service. Always autoscaling-managed — takes `--min`/`--max` (min may be `0`), never a count. |
| `--scheduler` | flag | Always errors — the scheduler is a singleton and can't be scaled. |
| `--min` / `--max` | int | Autoscaling bounds — the autoscaled form. |

There are two forms, picked by what you pass:

- **Autoscaled** — `--min`/`--max` set the bounds. The values are written back to the manifest (surgically — comments and formatting are preserved): web → [`tasks.web.autoscaling.min/max`](/reference/manifest#tasks-web-autoscaling), queue → [`tasks.queue.min/max`](/reference/manifest#tasks-queue). The scalable target is then registered, so the **manifest stays the source of truth** and the next sync reconciles to the same values. A desired count is never set under autoscaling (the policies would override it).
- **Fixed** — a positional `count` sets the ECS desired count directly (`UpdateService`), for a **web** service with no `autoscaling` block. A standalone queue is always autoscaling-managed, so passing it a count errors and points you to `--min/--max`.

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

Toggling and confirming applies the membership diff — and only ever touches these YOLO groups, never a user's other group memberships. Only groups that have actually been [synced](#yolo-sync) are offered (you can't grant a tier that isn't provisioned). It runs under the **admin** tier, whose policy can manage `yolo-*` group membership, so a member of `yolo-{env}-admins` can grant access to others. To grant deploy on a different app, run it in that app's directory. See [provisioning](/guide/provisioning).

---

## `yolo services`

View and manage the [services](/guide/provisioning#the-service-lifecycle) an environment offers — the two-key gate (the env manifest offers a service, an app claims it) made visible and editable.

```bash
yolo services <environment> [--json] [--add=<service>] [--set key=value] [--remove=<service>]
```

| Option | Value | Description |
|---|---|---|
| `--json` | flag | Print the service state as JSON and exit — no prompts (for agents/CI). |
| `--add` | service | Offer a service non-interactively (pair with `--set`). |
| `--set` | `key=value` | An offer field for `--add`, repeatable (e.g. `--set version=29.0 --set nodes=3`). |
| `--remove` | service | Withdraw a service offer non-interactively. |

Run with no options for an interactive table — each service's offer, the running apps that claim it, and its lifecycle state — with add / edit / remove. The add/edit prompts are generated from each service's offer fields, so a new service needs no command changes. App-side-only services (`rekognition`, `mediaconvert`) are listed but not offerable at the environment tier.

Editing writes and uploads the [environment manifest](/reference/manifest); the next [`sync:environment`](#yolo-sync-environment) reconciles AWS to it. A service can't be withdrawn while a running app still claims it (the same guard as [`environment:manifest:push`](#yolo-environment-manifest-push)).

```bash
yolo services production                                          # interactive
yolo services production --json                                   # read state
yolo services production --add=typesense --set version=29.0 --set nodes=3
yolo services production --remove=typesense
```

---

## `yolo tui`

Open the [interactive dashboard](/guide/tui) — a tabbed terminal UI over the environment.

```bash
yolo tui [environment]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | no | The environment name. Prompts when omitted (auto-selects the only one). |

Tabs: **Status** (live vitals, reusing [`status`](#yolo-status)), **Services** (the [`services`](#yolo-services) gate, ⏎ to manage), **Deployments** (ECR history + interactive [`rollback`](#yolo-rollback), and live rollout progress when one's in flight), **Logs** (tail CloudWatch per group), and **Manifest** (the env + app manifests, `e` to edit the env domain). The active tab's body is fitted to the terminal — tall content (logs, deploy history) scrolls. A global health bar stays on top and flags any in-progress deploy whoever triggered it. `tui` adds no new powers — it's the live cockpit over the existing commands.

Navigate with `◂ ▸` / `Tab` / number keys / a tab's hotkey; `↑ ↓` / `PgUp PgDn` / `Home End` scroll a tab's body; `q` quits. It's interactive only — for a one-off frame in a script use [`status`](#yolo-status) (which renders once and exits) or `status --json`.

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

Sync the environment-shared (environment-tier) resources — VPC, subnets, internet gateway and routes, the load balancer security group, the env config bucket holding [the environment's declaration](/guide/provisioning#the-environment-declaration) (env manifest + env-shared `.env`, the manifest seeded once on first sync), the env-backed services gated on [the service lifecycle](/guide/provisioning#the-service-lifecycle) (the IVS event-logging pipeline and the [Typesense search cluster](/guide/provisioning#typesense-the-environment-s-search-cluster) — each provisioned while the env manifest declares it **and** a running app uses it, and planned as a `WOULD DELETE` teardown once that stops being true), the ALB and its `:80` listener, the SNS alarm topic, the shared ECS execution IAM role, the env-shared `yolo-{env}-observer` read-only policy (the drift-check inspection surface every app's deployer role attaches — see [CI/CD](/guide/ci-cd#what-yolo-sync-provisions-for-ci)), the `yolo-{env}-observer-role` an operator or agent assumes for safe **read-only** inspection (it carries that policy — point a `*-readonly` profile at it), the env-wide [grant groups](#conventions) (`yolo-{env}-observers`, `yolo-{env}-admins`) whose membership grants the read / admin tier, and the [WAF web ACL](/guide/provisioning#web-application-firewall) (with its allow/block IP sets) fronting the ALB.

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

A per-app **CloudWatch dashboard** (`yolo-<env>-<app>-dashboard`) is generated last, so every resource it charts already exists. It panels the ECS service (CPU/memory/tasks), the ALB (target health, requests, latency, slow-request bands, error counts and a 5xx error-rate SLO), SQS depth/throughput, the asset CloudFront distribution (requests, errors and cache hit rate), the S3 buckets, any consumed services (MediaConvert jobs, Rekognition requests) and the app's logs — plus an RDS panel derived from `DB_HOST` in the app's env file (CPU, connections, memory, throughput and read/write latency). It's a read-only convenience: CloudWatch dashboards can't carry tags, so it doesn't appear in `yolo audit`.

When a [`tasks.web.autoscaling`](/reference/manifest#tasks-web-autoscaling) block is present, `sync:app` also registers the **scalable target** and its **target-tracking policies** (request concurrency by default, derived from task memory, plus CPU as a safety net), right after the ECS service. App Auto Scaling targets aren't taggable either, so they're invisible to `yolo audit` too. If autoscaling is enabled on a task that also runs the scheduler, the sync plan lists an advisory under its **Warnings** section — see [Scaling](/guide/scaling). Scaling is web-only and inert without the manifest block.

---

## `yolo audit`

Audit YOLO-tagged resources for an environment (account → environment → app) and flag anything not accounted for. Read-only.

```bash
yolo audit <environment> [--unexpected] [--json]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Description |
|---|---|---|
| `--unexpected` | flag | Only show unexpected resources — anything not accounted for by YOLO. |
| `--json` | flag | Emit the audit as JSON (`{environment, liveApps, okCount, unexpectedCount, resources}`) and exit — machine-readable for the `/yolo` skill and scripts. Honours the same scope and `--unexpected` filtering as the table. |

Queries the Resource Groups Tagging API for everything tagged `yolo:environment=<env>` and classifies each resource as **`ok`** or **`unexpected`**, with a **Reason** explaining each unexpected row — `no ownership tag`, `service no longer provisioned`, or `app cluster gone` (see [Provisioning › Auditing](/guide/provisioning#auditing-what-s-deployed)). Audit is an ownership/inventory check; it does not inspect a resource's configuration (that's `sync`'s job). Results are grouped by scope, unexpected-first within a scope, with clickable AWS Console links where the terminal supports them.

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
