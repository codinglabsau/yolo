# Command Reference

Every YOLO command, with its arguments and options. Run `vendor/bin/yolo` with no arguments to list them, or `vendor/bin/yolo <command> --help` for Symfony's generated usage.

## Conventions

- **`<environment>`** — almost every command takes a required `environment` argument naming a key under `environments` in your `yolo.yml` (e.g. `production`, `staging`).
- **AWS authentication** — outside CI, YOLO reads a named AWS profile from `YOLO_<ENVIRONMENT>_AWS_PROFILE` in your local `.env`. Before any AWS call it verifies (via STS) that the profile resolves to the `account-id` declared in the manifest. The `default` profile is rejected. In CI it falls back to the AWS SDK default credential chain (GitHub OIDC, SSO).
- **Required manifest keys** — every command except `init` checks that `name`, `region`, and `account-id` are declared, and fails fast if not.

## Commands at a glance

| Command | Purpose |
|---|---|
| [`init`](#yolo-init) | Scaffold `yolo.yml`, Dockerfile, and supporting files |
| [`env:pull <env>`](#yolo-env-pull) | Download the environment's `.env` from S3 |
| [`env:push <env>`](#yolo-env-push) | Upload the environment's `.env` to S3 (with diff) |
| [`build <env>`](#yolo-build) | Build and push the container image |
| [`deploy <env>`](#yolo-deploy) | Build, then roll out a zero-downtime deploy |
| [`status <env>`](#yolo-status) | Live dashboard of services, load, scaling and any in-progress deploy |
| [`run <env>`](#yolo-run) | Open a shell / run a command in a running container |
| [`scale <env> [count]`](#yolo-scale) | Adjust the web service's task count out of band |
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

- Writes `yolo.yml` from the stub.
- Writes a default `Dockerfile` and `.dockerignore` (asks before overwriting existing ones).
- Creates a starter `.env.production`.
- Appends `.yolo`, `.env.staging`, and `.env.production` to `.gitignore`.
- Offers to install the AWS Session Manager plugin (used by [`run`](#yolo-run)).

This is the only command that runs without an existing manifest.

---

## `yolo env:pull`

Download the environment file for the given environment from the app's S3 config bucket.

```bash
yolo env:pull <environment> [--shared]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--shared` | flag | off | Pull the env-shared files instead of the app env file — the [env manifest](/reference/manifest#the-environment-manifest-yolo-environment-yml) to `yolo-<environment>.yml` and the env-shared `.env` to `.env.<environment>.shared` (both gitignored). |

Without `--shared`, writes `.env.<environment>` to your project root, overwriting any local copy. With `--shared`, the env manifest must already exist (the environment's first `sync` seeds it); a missing env-shared `.env` is fine — it's created by your first push.

---

## `yolo env:push`

Upload the environment file for the given environment to the app's S3 config bucket.

```bash
yolo env:push <environment> [--shared]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--shared` | flag | off | Push the env-shared files instead of the app env file — whichever of `yolo-<environment>.yml` and `.env.<environment>.shared` exist locally, each with its own key-level diff and confirmation. The env manifest is validated against its schema **before** upload, so a misshapen manifest can never reach the bucket. |

Downloads the current remote file, shows a diff of changed keys, and asks for confirmation before uploading. If no remote file exists yet, it uploads without a diff.

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

Build, push, and deploy the application — runs [`build`](#yolo-build) first, then the zero-downtime rollout.

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

After building, `deploy` pushes assets to S3, registers a new task-definition revision **for each service group** (web plus any standalone queue/scheduler), runs `deploy` hooks as a one-off task, rolls each ECS service onto its new revision, waits for the web service to go healthy (the deployment circuit breaker auto-rolls-back on failure), then UPSERTs Route 53 records. It always waits for the rollout to stabilise — there is no opt-out flag. `--group` narrows the rollout to a subset of services (the shared image is built either way); a deploy that omits `web` skips the ALB health wait, relying on the circuit breaker.

Once the rollout settles, `deploy` prints a recap — the same per-group summary table and CloudWatch dashboard link [`status`](#yolo-status) shows — so you can see what's now running and the new revision of each service.

---

## `yolo status`

Show a live dashboard of the app's running state for an environment — what each service group is running, its current load, scaling configuration, and any deploy in progress.

```bash
yolo status <environment> [--snapshot]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--snapshot` | flag | off | Render one frame and exit instead of running the live dashboard. |

The dashboard has three panels, read live from ECS, Application Auto Scaling and CloudWatch:

- **Deployment in progress** (only when a rollout is mid-flight) — a progress bar of new-revision tasks per rolling group, its rollout state, the revision, and how long it's been running.
- **Services** — one row per group (web / queue / scheduler) with the task spec (vCPU/memory/launch type), running/desired task count, scaling bounds + policies (`1–4 auto (cpu 65%, req 1200)`, or `fixed` / `singleton`), and the deployed revision + app version.
- **Load** (last 5 min) — ECS CPU/memory per group, shown against the CPU scaling target so headroom is obvious, plus the web service's ALB request rate and response time.

Below the panels is a clickable deep link to the app's CloudWatch dashboard for the full metrics view.

By default it **polls and redraws until you quit** (Ctrl-C), picking up any deploy that starts while it's open — so it doubles as a live deploy watch. `--snapshot` (and any non-interactive shell) renders a single frame and exits instead, returning a non-zero exit code if a deployment is currently failed.

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

Sync the environment-shared (environment-tier) resources — VPC, subnets, internet gateway and routes, the load balancer security group, the ALB and its `:80` listener, the SNS alarm topic, the shared ECS execution IAM role, and the [WAF web ACL](/guide/provisioning#web-application-firewall) (with its allow/block IP sets) fronting the ALB.

```bash
yolo sync:environment <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **environment**. These resources are shared by every app in the environment; apps attach to them but never mutate them.

---

## `yolo sync:app`

Sync a single application's resources for the given environment — S3 buckets, app IAM (deployer role/policy, the per-app ECS task role plus any [`task-role-policies`](/reference/manifest#task-role-policies), MediaConvert role for IVS), ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, SQS queues, a CloudWatch dashboard, target-tracking autoscaling (when configured), and — for a solo app — its hosted zone and ACM certificate. For web apps it also provisions the shared [Valkey cache](/guide/provisioning#cache-and-sessions) (`cache.store`, default-on); sessions ride the same cluster by default ([`session.driver: redis`](/guide/provisioning#cache-and-sessions)), so they need no resources of their own.

```bash
yolo sync:app <environment> [--check] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **app**.

The step set is mode-aware: a multi-tenant app fans out landlord + per-tenant queues (and skips the solo hosted zone/cert); a solo app gets the apex zone + certificate. Web/CDN steps only run when `tasks.web` is declared. Use `--tenant=<id>` to narrow per-tenant steps to one tenant.

Some environment-tier resources are bootstrapped here by exception — the RDS security group (because its real purpose is this app's task-SG ingress), the HTTPS `:443` listener (because its creation needs this app's certificate), and the shared Valkey cache when `cache.store` is set (its security group needs this app's task SG to authorise). All are created-if-missing and never mutated, so the environment tier remains their single writer.

A per-app **CloudWatch dashboard** (`yolo-<env>-<app>-dashboard`) is generated last, so every resource it charts already exists. It panels the ECS service (CPU/memory/tasks), the ALB (target health, requests, latency, slow-request bands, error counts and a 5xx error-rate SLO), SQS depth/throughput, the asset CloudFront distribution (requests, errors and cache hit rate), the S3 buckets and the app's logs — plus an RDS panel derived from `DB_HOST` in the app's env file (CPU, connections, memory, throughput and read/write latency). It's a read-only convenience: CloudWatch dashboards can't carry tags, so it doesn't appear in `yolo audit`.

When a [`tasks.web.autoscaling`](/reference/manifest#tasks-web-autoscaling) block is present, `sync:app` also registers the **scalable target** and its **target-tracking policies** (CPU always; request-count once `request-count-per-target` is set), right after the ECS service. App Auto Scaling targets aren't taggable either, so they're invisible to `yolo audit` too. If autoscaling is enabled on a task that also runs the scheduler, the sync plan lists an advisory under its **Warnings** section — see [Scaling](/guide/scaling). Scaling is web-only and inert without the manifest block.

---

## `yolo audit`

Audit YOLO-tagged resources for an environment (account → environment → app) and flag anything not accounted for. Read-only.

```bash
yolo audit <environment> [--unexpected]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Description |
|---|---|---|
| `--unexpected` | flag | Only show unexpected resources — anything not accounted for by YOLO. |

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
