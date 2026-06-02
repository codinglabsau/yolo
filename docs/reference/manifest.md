# Manifest Reference

`yolo.yml` is the single source of truth for your application's infrastructure. Both `yolo sync` (infrastructure) and `yolo deploy` (code) read from it. This page documents every key.

## A complete example

This is roughly what `yolo init` scaffolds — a single-app `production` environment running a web task with the queue and scheduler enabled:

```yaml
name: codinglabs

environments:
  production:
    # Public domain. Omit both for a headless app (no ALB / DNS).
    domain: codinglabs.com.au
    # apex: codinglabs.com.au   # set separately when domain is a subdomain

    # Source ref this environment deploys from (drives the CI deployer OIDC trust).
    # Defaults to the main branch.
    # branch: main
    # tag: 'v*'
    # repository: org/repo

    account-id: '123456789012'
    region: ap-southeast-2
    # bucket: my-app-bucket     # optional app S3 bucket, injected as AWS_BUCKET

    # Web apps default to these — uncomment only to override or opt out:
    # cache:
    #   store: redis            # default; file/database/array to opt out of the shared Valkey
    # session:
    #   driver: dynamodb        # default; redis/database/cookie/file to change the session backend

    tasks:
      web:
        cpu: '512'
        memory: '1024'
        port: 8000
        enable-execute-command: true
        queue: true
        scheduler: true

    build:
      - composer install --no-cache --no-interaction --optimize-autoloader --no-progress --classmap-authoritative --no-dev
      - npm ci
      - npm run build
      - rm -rf package-lock.json node_modules database/seeders database/factories

    deploy:
      - php artisan migrate --force

    deploy-all:
      - php artisan optimize
```

::: warning Required keys
Every command except `init` fails fast unless these three are present:

- **`name`** (top level)
- **`region`** (per environment)
- **`account-id`** (per environment)
:::

## Top-level keys

### `name`

**Required.** The application name. Used as the prefix for app-scoped AWS resource names (`yolo-{env}-{name}-…`) and the deployer role.

### `timezone`

The timezone used to compute the `year.week` prefix for build versions. Defaults to `UTC`. Set it to your team's timezone so a release cut near a week boundary doesn't trip [app-version validation](/guide/building-and-deploying#app-version).

### `environments`

**Required.** A map of environment name → environment config. The key (`production`, `staging`, …) is the `<environment>` argument you pass to commands.

---

## Routing keys

These live directly under an environment and determine how the app is reached. See [Domains](/guide/domains).

### `domain`

The public domain the app is served on (e.g. `app.example.com`). When it's the apex, both the apex and its `www.` subdomain are served. Omit for a [headless app](/guide/domains#headless-apps).

### `apex`

The registrable root domain, naming the Route 53 hosted zone to write into. Defaults to `domain`. Set it explicitly when `domain` is a subdomain. Cannot start with `www.`.

### `tenants`

A map of tenant id → `{ domain, apex }` that puts the app in [multi-tenant mode](/guide/multi-tenancy). When set, `domain`/`apex` must **not** be set at the environment level.

```yaml
tenants:
  acme:
    domain: acme.example.com
  globex:
    domain: globex-with-yolo.com
```

### `branch` / `tag` / `repository`

Control the CI deployer role's OIDC trust — see [CI/CD](/guide/ci-cd).

- **`branch`** — the branch this environment deploys from (default `main`).
- **`tag`** — a tag pattern (e.g. `'v*'`, or `true` for any tag) instead of a branch.
- **`repository`** — `org/repo`, inferred from your git origin if omitted; set only to override (monorepo / fork).

---

## Infrastructure keys

These live directly under an environment and provision or configure the app's AWS resources. (There is no `aws.` namespace — YOLO is AWS-only, so every key sits at the top of the environment block.)

### `account-id`

**Required.** The AWS account ID to deploy into. Verified against your resolved profile via STS before any change is made.

### `region`

**Required.** The AWS region (e.g. `ap-southeast-2`).

### `bucket`

Name of an app S3 bucket for application storage. Injected into the container as `AWS_BUCKET`.

### `alb`

Name of the Application Load Balancer to use. Defaults to the per-environment shared `yolo-{env}` ALB.

### `artefacts-bucket`

Bucket holding env files and build artefacts. Defaults to `yolo-{env}-artefacts`.

### `alb-logs-bucket`

Bucket for ALB access logs. Defaults to `yolo-{env}-alb-logs`.

### `ivs`

Enables IVS (Amazon Interactive Video Service) event logging. Set to `true`, or expand to a map for finer control:

```yaml
ivs:
  logging: true
  log-retention-days: 30   # CloudWatch retention (default 14)
```

### `mediaconvert`

MediaConvert role ARN for video transcoding workloads (used with IVS).

### `sqs.*`

Queue depth CloudWatch alarm tuning:

| Key | Default | Description |
|---|---|---|
| `sqs.depth-alarm-threshold` | `100` | Messages before the alarm fires. |
| `sqs.depth-alarm-period` | `300` | Evaluation period in seconds. |
| `sqs.depth-alarm-evaluation-periods` | `3` | Number of periods that must breach. |

### Adopting existing infrastructure (advanced)

By default YOLO creates and names shared networking under `yolo-{env}-…`. To point it at resources you already have, set their id/name. These are escape hatches — most apps never touch them.

| Key | Default | Adopts |
|---|---|---|
| `vpc` | `yolo-{env}` | VPC |
| `internet-gateway` | `yolo-{env}` | Internet gateway |
| `route-table` | `yolo-{env}` | Route table |
| `public-subnets` | derived per env | Public subnet CIDRs |
| `rds.subnet` | — | RDS subnet |
| `rds.security-group` | `yolo-{env}-rds` | RDS security group |
| `ecs.security-group` | `yolo-{env}-{app}` | ECS task security group |
| `ecs.cluster` | `yolo-{env}-{app}` | ECS cluster name |

---

## `cache.*`

Declares the app's cache store. **Web apps (`tasks.web`) default to `redis`** — the per-task filesystem is broken across multiple Fargate tasks, so a working shared cache is the right default. `redis` provisions a shared **ElastiCache for Valkey** cache for the environment (one cluster shared by every app, isolated by a per-app key prefix). Set `cache.store` to `file`, `database`, or `array` to opt out (app-managed, nothing provisioned). Non-web apps get no default.

```yaml
cache:
  store: redis   # the web-app default; set file/database/array to opt out
```

`redis` provisions, with hardcoded sensible defaults (no tuning knobs until a real need lands):

- a single-node replication group on `cache.t4g.micro` (auto-failover / Multi-AZ off — a standard single instance, ~A$11/mo), at-rest encryption on;
- `maxmemory-policy=allkeys-lru` (writes never fail under memory pressure);
- a security group allowing ingress on `6379` **only** from the Fargate task security group (the cache has no public endpoint);
- a cache subnet group across the VPC subnets.

With the `redis` store, the container env gets `CACHE_STORE=redis`, `REDIS_HOST` (the cluster's primary endpoint), `REDIS_PORT=6379`, and a per-app `REDIS_PREFIX` — each only if your `.env` doesn't already set it. Scaling is a manual vertical resize; there's no autoscaling. For availability, see the [Laravel `failover` cache store](/guide/provisioning#cache-high-availability) rather than adding a replica. For a backend YOLO doesn't model, set `CACHE_STORE` in your `.env` and `cache.store: file`.

---

## `session.*`

Declares the app's session backend. **Web apps (`tasks.web`) default to `dynamodb`** — a managed, multi-AZ store, so sessions survive a task/node loss (the ephemeral filesystem and a single cache node don't). YOLO injects `SESSION_DRIVER` (only if your `.env` doesn't already set it) and provisions infrastructure **only for the driver that needs it**. Non-web apps have no sessions, so no default.

```yaml
session:
  driver: dynamodb   # the web-app default; redis | dynamodb | database | cookie | file
```

| `session.driver` | YOLO provisions | Also injects | Notes |
|---|---|---|---|
| `dynamodb` | A per-app **DynamoDB** table (`yolo-{env}-{app}-sessions`, on-demand billing, TTL on `expires_at`) | `DYNAMODB_CACHE_TABLE` | Laravel's `dynamodb` session driver is cache-backed, so the table uses the Laravel cache schema. Multi-AZ by default — no single point of failure, the durable choice for sessions. The task role is granted DynamoDB access to `yolo-{env}-*` tables. App needs `aws/aws-sdk-php`. |
| `redis` | Nothing new (reuses the Valkey cache) | — | **Requires `cache.store: redis`** — there's no redis store without it. Sessions share the single Valkey node, so a node loss logs users out. |
| `database` / `cookie` / `file` | Nothing | — | App-managed. `cookie` is capped at ~4&nbsp;KB per browser cookie — risky once flashed validation errors are stored. |

On a web app, omitting `session` gives you the `dynamodb` default; set a driver to override it. On a non-web app, `SESSION_DRIVER` is left to your `.env`.

---

## `tasks.web.*`

Declaring `tasks.web` makes the app a Fargate web service. Omit `tasks` entirely for a build-only / headless app with no container.

| Key | Default | Description |
|---|---|---|
| `tasks.web.port` | `8000` | Container port. Must match the Dockerfile's exposed port and the health check. |
| `tasks.web.cpu` | `'512'` | Fargate CPU units. |
| `tasks.web.memory` | `'1024'` | Fargate memory (MB). |
| `tasks.web.dockerfile` | `Dockerfile` | Path to the Dockerfile. |
| `tasks.web.platform` | `linux/amd64` | Docker build platform. |
| `tasks.web.image` | built & pushed to ECR | Override to use a pre-built image instead of building one. |
| `tasks.web.enable-execute-command` | `false` | Enable ECS Exec so [`yolo run`](/reference/commands#yolo-run) can attach. Gate access with MFA on your IAM. |
| `tasks.web.queue` | `false` | Run `queue:work` in the container. `true`, or an object to override its `shutdown-grace-period`. |
| `tasks.web.scheduler` | `false` | Run the Laravel scheduler (cron + `schedule:run`). `true`, or an object form like `queue`. |
| `tasks.web.shutdown-grace-period` | `10` (web), `70` (queue) | Seconds a process gets on `SIGTERM` before `SIGKILL`. For web it's also the ALB drain window and the container `stopTimeout`. See [graceful shutdown](/guide/images#graceful-shutdown). |
| `tasks.web.log-retention` | `30` | CloudWatch Logs retention (days). Must be a valid CloudWatch retention value. |
| `tasks.web.execution-role` | shared `yolo-{env}` role | Override the ECS execution role ARN. |
| `tasks.web.task-role` | shared `yolo-{env}` role | Override the ECS task role ARN. |

### `tasks.web.health-check.*`

ALB target-group health check:

| Key | Default | Description |
|---|---|---|
| `health-check.path` | `/health` | Path the ALB requests. |
| `health-check.interval` | `10` | Seconds between checks. |
| `health-check.timeout` | `5` | Seconds before a check times out. |
| `health-check.healthy-threshold` | `2` | Consecutive successes to mark healthy. |
| `health-check.unhealthy-threshold` | `3` | Consecutive failures to mark unhealthy. |
| `health-check.grace-period` | `60` | Seconds after task start before health checks count (the ECS health-check grace period). |

### `tasks.web.image-retention.*`

ECR lifecycle policy for the app's repository:

| Key | Default | Description |
|---|---|---|
| `image-retention.keep-count` | `30` | Tagged images to keep. |
| `image-retention.untagged-days` | `7` | Days to keep untagged images. |

---

## Deploy hooks

Three arrays run shell commands at different points in the pipeline — see [Building & Deploying](/guide/building-and-deploying#hooks-build-vs-deploy-vs-deploy-all).

### `build`

Runs at build time on your machine, in the build context. For dependency installation and asset compilation.

### `deploy`

Runs once per deploy as a one-off ECS task, before traffic shifts. For migrations.

### `deploy-all`

Runs on every container start (via the entrypoint). For cache warming like `php artisan optimize`.

---

## App modes

Your manifest implies one of three modes:

| Mode | Condition | Behaviour |
|---|---|---|
| **Solo** | `domain`/`apex` set at the environment level | One app, one hosted zone + certificate, served on its domain. |
| **Multi-tenant** | `tenants` set (no env-level `domain`/`apex`) | Per-tenant domains and queues; certs attach per tenant via SNI. |
| **Headless** | no `domain`, `apex`, or tenant domains | No ALB attachment or DNS. Still deploys and processes queues/scheduled work. |
