# Command Reference

Every YOLO command, with its arguments and options. Run `vendor/bin/yolo` with no arguments to list them, or `vendor/bin/yolo <command> --help` for Symfony's generated usage.

## Conventions

- **`<environment>`** — almost every command takes a required `environment` argument naming a key under `environments` in your `yolo.yml` (e.g. `production`, `staging`).
- **AWS authentication** — outside CI, YOLO reads a named AWS profile from `YOLO_<ENVIRONMENT>_AWS_PROFILE` in your local `.env`. Before any AWS call it verifies (via STS) that the profile resolves to the `aws.account-id` declared in the manifest. The `default` profile is rejected. In CI it falls back to the AWS SDK default credential chain (OIDC, SSO, static keys).
- **Required manifest keys** — every command except `init` checks that `name`, `aws.region`, and `aws.account-id` are declared, and fails fast if not.

## Commands at a glance

| Command | Purpose |
|---|---|
| [`init`](#yolo-init) | Scaffold `yolo.yml`, Dockerfile, and supporting files |
| [`env:pull <env>`](#yolo-env-pull) | Download the environment's `.env` from S3 |
| [`env:push <env>`](#yolo-env-push) | Upload the environment's `.env` to S3 (with diff) |
| [`build <env>`](#yolo-build) | Build and push the container image |
| [`deploy <env>`](#yolo-deploy) | Build, then roll out a zero-downtime deploy |
| [`run <env>`](#yolo-run) | Open a shell / run a command in a running container |
| [`sync <env>`](#yolo-sync) | Provision all resources (account → environment → app) |
| [`sync:account <env>`](#yolo-sync-account) | Provision account-global resources |
| [`sync:environment <env>`](#yolo-sync-environment) | Provision environment-shared resources |
| [`sync:app <env>`](#yolo-sync-app) | Provision one app's resources |
| [`audit <env>`](#yolo-audit) | Audit tagged resources and flag drift |
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

Download the environment file for the given environment from the S3 artefacts bucket.

```bash
yolo env:pull <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

Writes `.env.<environment>` to your project root, overwriting any local copy.

---

## `yolo env:push`

Upload the environment file for the given environment to the S3 artefacts bucket.

```bash
yolo env:push <environment>
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

**Options:** none

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
yolo deploy <environment> [--app-version=<tag>] [--no-progress]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Default | Description |
|---|---|---|---|
| `--app-version` | string | timestamp `y.W.N.Hi` | Tag to stamp on the build (same rules as `build`). |
| `--no-progress` | flag | off | Hide the live progress output. |

After building, `deploy` pushes assets to S3, registers a new task-definition revision, runs `deploy` hooks as a one-off task, updates the ECS service, waits for it to go healthy (the deployment circuit breaker auto-rolls-back on failure), then UPSERTs Route 53 records. It always waits for the rollout to stabilise — there is no opt-out flag.

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

**Requirements:** the AWS [Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) installed locally, and `tasks.web.enable-execute-command: true` in the manifest.

```bash
yolo run production
yolo run production --command="php artisan migrate:status"
yolo run production --command="php artisan queue:restart" --group=web,queue
```

::: tip
Today web, queue, and scheduler all run in the single `web` container, so the groups collapse onto it — the distinction matters once independent task groups land.
:::

---

## `yolo sync`

Sync **all** resources for the given environment, orchestrating the three scopes in dependency order: account → environment → app.

```bash
yolo sync <environment> [--dry-run] [--force] [--no-progress] [--tenant=<id>]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

<a id="sync-options"></a>

| Option | Short | Value | Description |
|---|---|---|---|
| `--dry-run` | | flag | Show what would change without applying it. |
| `--force` | `-f` | flag | Skip the confirmation prompt. |
| `--no-progress` | | flag | Hide the live progress output. |
| `--tenant` | | string | Limit per-tenant steps to a single tenant id. |

These four options are shared by every `sync` command below. See [Provisioning](/guide/provisioning) for the plan/confirm/apply flow.

---

## `yolo sync:account`

Sync the account-global resources (shared across every environment) — the GitHub OIDC identity provider.

```bash
yolo sync:account <environment> [--dry-run] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **account**.

---

## `yolo sync:environment`

Sync the environment-shared (environment-tier) resources — VPC, subnets, internet gateway and routes, the load balancer security group, the ALB and its `:80` listener, the SNS alarm topic, and the shared ECS task & execution IAM roles.

```bash
yolo sync:environment <environment> [--dry-run] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **environment**. These resources are shared by every app in the environment; apps attach to them but never mutate them.

---

## `yolo sync:app`

Sync a single application's resources for the given environment — S3 buckets, app IAM (deployer role/policy, MediaConvert role for IVS), ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, SQS queues, a CloudWatch dashboard, and — for a solo app — its hosted zone and ACM certificate.

```bash
yolo sync:app <environment> [--dry-run] [--force] [--no-progress] [--tenant=<id>]
```

Arguments and options as [`sync`](#sync-options). Scope: **app**.

The step set is mode-aware: a multi-tenant app fans out landlord + per-tenant queues (and skips the solo hosted zone/cert); a solo app gets the apex zone + certificate. Web/CDN steps only run when `tasks.web` is declared. Use `--tenant=<id>` to narrow per-tenant steps to one tenant.

Two environment-tier resources are bootstrapped here by exception — the RDS security group (because its real purpose is this app's task-SG ingress) and the HTTPS `:443` listener (because its creation needs this app's certificate). Both are created-if-missing and never mutated, so the environment tier remains their single writer.

A per-app **CloudWatch dashboard** (`yolo-<env>-<app>-dashboard`) is generated last, so every resource it charts already exists. It panels the ECS service (CPU/memory/tasks), the ALB (target health, requests, latency, slow-request bands, error counts and a 5xx error-rate SLO), SQS depth/throughput, the asset CloudFront distribution (requests, errors and cache hit rate), the S3 buckets and the app's logs — plus an RDS panel derived from `DB_HOST` in the app's env file (CPU, connections, memory, throughput and read/write latency). It's a read-only convenience: CloudWatch dashboards can't carry tags, so it doesn't appear in `yolo audit`.

---

## `yolo audit`

Audit YOLO-tagged resources for an environment (account → environment → app) and flag unexplained drift. Read-only.

```bash
yolo audit <environment> [--drift]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |

| Option | Value | Description |
|---|---|---|
| `--drift` | flag | Only show drift — resources tagged for an app that is no longer live. |

Queries the Resource Groups Tagging API for everything tagged `yolo:environment=<env>` and classifies each resource as **`ok`**, **`drift`**, or **`rogue`** (see [Provisioning › Auditing](/guide/provisioning#auditing-what-s-deployed)). Results are grouped by scope, drift-first within a scope, with clickable AWS Console links where the terminal supports them.

---

## `yolo audit:environment`

Audit only the environment-tier resources for the given environment.

```bash
yolo audit:environment <environment> [--drift]
```

Arguments and options as [`audit`](#yolo-audit). Filters to environment-scope rows. Environment-scope resources never carry `yolo:app`, so `--drift` is a no-op here — drift is an app-scope concept.

---

## `yolo audit:app`

Audit a single app's resources for the given environment.

```bash
yolo audit:app <environment> <app> [--drift]
```

| Argument | Required | Description |
|---|---|---|
| `environment` | yes | The environment name |
| `app` | yes | The app name (matches the resource's `yolo:app` tag) |

| Option | Value | Description |
|---|---|---|
| `--drift` | flag | Only show drift for this app. |

Filters the environment-wide report to rows whose `yolo:app` tag matches `<app>`, so only `ok` and `drift` rows for that app appear (a `rogue` resource has no `yolo:app`, so it never shows here).
