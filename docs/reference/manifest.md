# Manifest Reference

`yolo.yml` is the single source of truth for your application's infrastructure. Both `yolo sync` (infrastructure) and `yolo deploy` (code) read from it. This page documents every key.

## A complete example

Every key YOLO understands, in one annotated `yolo.yml`. **Required keys are uncommented; everything else is commented out showing its default**, so you can copy this and uncomment only what you need to change. `yolo init` scaffolds a minimal subset of this — a web app with the queue and scheduler on.

```yaml
name: codinglabs                 # required — app name, prefixes every app-scoped resource
# timezone: Australia/Brisbane   # default UTC — sets the year.week build-version prefix

environments:
  production:
    # --- Required ---
    account-id: '123456789012'   # required — verified against your AWS profile via STS
    region: ap-southeast-2       # required

    # --- Routing (see /guide/domains) ---
    domain: codinglabs.com.au    # public domain; omit domain + apex + tenants for a headless app
    # apex: codinglabs.com.au    # default: domain — set when domain is a subdomain
    #
    # Multi-tenant instead of a single domain (mutually exclusive with domain/apex):
    # tenants:
    #   acme:   { domain: acme.example.com }
    #   globex: { domain: globex.example.com }

    # --- CI deployer OIDC trust (see /guide/ci-cd) ---
    # branch: main               # default: main — branch this env deploys from
    # tag: 'v*'                  # deploy on a tag pattern instead of a branch
    # repository: org/repo       # default: inferred from your git origin

    # --- App storage & shared infra names ---
    # bucket: my-app-bucket                          # app S3 bucket, injected as AWS_BUCKET
    # artefacts-bucket: yolo-production-artefacts    # default: yolo-{env}-artefacts
    # alb: yolo-production                           # default: yolo-{env} — shared ALB to attach to
    # alb-logs-bucket: yolo-production-alb-logs      # default: yolo-{env}-alb-logs

    # --- Cache & session (web apps default to these; uncomment only to override) ---
    # cache:
    #   store: redis             # default; file/database/array to opt out of the shared Valkey
    # session:
    #   driver: redis            # default (sessions live on the Valkey cluster); database/cookie/file to change the session backend

    # --- Media: Amazon IVS + MediaConvert ---
    # ivs: true                  # enable IVS event logging; or expand for finer control:
    # ivs:
    #   logging: true
    #   log-retention-days: 30   # default: 14 — CloudWatch retention
    # mediaconvert: arn:aws:iam::123456789012:role/MediaConvertRole   # transcoding role ARN (used with IVS)

    # --- Queue depth alarm tuning ---
    # sqs:
    #   depth-alarm-threshold: 100          # default: 100 — messages before the alarm fires
    #   depth-alarm-period: 300             # default: 300 — evaluation period (seconds)
    #   depth-alarm-evaluation-periods: 3   # default: 3 — periods that must breach

    tasks:
      web:
        cpu: '512'               # default: '512' — Fargate CPU units
        memory: '1024'           # default: '1024' — Fargate memory (MB)
        port: 8000               # default: 8000 — must match the Dockerfile & health check
        enable-execute-command: true   # default: false — enables `yolo run` to attach (gate with MFA)
        queue: true              # default: false — run queue:work in the container
        scheduler: true          # default: false — run cron + schedule:run
        # platform: linux/amd64           # default: linux/amd64
        # shutdown-grace-period: 10       # default: 10 (web) / 70 (queue) — SIGTERM→SIGKILL window
        # log-retention: 30               # default: 30 — CloudWatch Logs retention (days)
        # execution-role: arn:aws:iam::123456789012:role/...   # default: shared yolo-{env} role
        # task-role: arn:aws:iam::123456789012:role/...        # default: shared yolo-{env} role
        #
        # health-check:
        #   path: /health                 # default: /health
        #   interval: 10                  # default: 10 (seconds between checks)
        #   timeout: 5                    # default: 5
        #   healthy-threshold: 2          # default: 2
        #   unhealthy-threshold: 3        # default: 3
        #   grace-period: 60              # default: 60 (ECS health-check grace period)
        #
        # autoscaling:                    # omit the whole block for a fixed single task
        #   min: 1                        # default: 1
        #   max: 4                        # default: 4
        #   cpu-utilization: 65           # default: 65 — always-on CPU target-tracking policy
        #   request-count-per-target: 1000   # no default — seed from a load test (req/task/min)
        #   scale-out-cooldown: 60        # default: 60
        #   scale-in-cooldown: 300        # default: 300

    build:
      - composer install --no-cache --no-interaction --optimize-autoloader --no-progress --classmap-authoritative --no-dev
      - npm ci
      - npm run build
      - rm -rf package-lock.json node_modules database/seeders database/factories

    deploy:
      - php artisan migrate --force

    deploy-all:
      - php artisan optimize

    # --- Adopting existing infrastructure (advanced escape hatches; most apps never set these) ---
    # vpc: vpc-0abc123                      # default: yolo-{env}
    # internet-gateway: igw-0abc123         # default: yolo-{env}
    # route-table: rtb-0abc123              # default: yolo-{env}
    # public-subnets: [subnet-0aaa, subnet-0bbb]   # default: derived per env
    # rds:
    #   subnet: my-db-subnet-group          # adopt an existing RDS subnet group
    #   security-group: sg-0abc123          # default: yolo-{env}-rds
    # ecs:
    #   security-group: sg-0def456          # default: yolo-{env}-{app}
```

> Every commented key above has its own section below with the full semantics — this block is the map; the sections are the detail.

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

Declares the app's session backend. **Web apps (`tasks.web`) default to `redis`** — sessions land on the shared Valkey cluster, which gives strong read-after-write consistency (~1&nbsp;ms), so a session is readable the instant after it's written (no stale-read flicker right after login). YOLO injects `SESSION_DRIVER` (only if your `.env` doesn't already set it) and provisions infrastructure **only for the driver that needs it**. Non-web apps have no sessions, so no default.

```yaml
session:
  driver: redis   # the web-app default; redis | database | cookie | file
```

| `session.driver` | YOLO provisions | Also injects | Notes |
|---|---|---|---|
| `redis` (default) | Nothing new (reuses the Valkey cache) | `SESSION_DRIVER` only | **Requires `cache.store: redis`** (the web-app default) — there's no redis store without it, and YOLO hard-fails if you opt the cache out without re-pinning the session driver. Sessions use Laravel's stock `default` redis connection (**DB 0**), separate from the cache connection (**DB 1**), so sessions and cache share the Valkey instance but **not** the keyspace — a `cache:clear` never touches sessions. `SESSION_CONNECTION` is left unset so the null connection resolves to `default`. This relies on cluster-mode-disabled Valkey (the YOLO default), where logical databases exist. A single-node cluster has no session HA — a node loss logs users out (see [Cache high availability](/guide/provisioning#cache-high-availability)). |
| `database` / `cookie` / `file` | Nothing | `SESSION_DRIVER` only | App-managed (pin-only). `cookie` is capped at ~4&nbsp;KB per browser cookie — risky once flashed validation errors are stored. |

On a web app, omitting `session` gives you the `redis` default; set a driver to override it. On a non-web app, `SESSION_DRIVER` is left to your `.env`.

> DynamoDB is no longer a supported session backend. A manifest still setting `session.driver: dynamodb` hard-fails validation with a pointer to `redis`.

---

## `tasks.web.*`

Declaring `tasks.web` makes the app a Fargate web service. Omit `tasks` entirely for a build-only / headless app with no container.

| Key | Default | Description |
|---|---|---|
| `tasks.web.port` | `8000` | Container port. Must match the Dockerfile's exposed port and the health check. |
| `tasks.web.cpu` | `'512'` | Fargate CPU units. |
| `tasks.web.memory` | `'1024'` | Fargate memory (MB). |
| `tasks.web.platform` | `linux/amd64` | Docker build platform. |
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

### `tasks.web.autoscaling.*`

Add an `autoscaling` block to turn on [Application Auto Scaling](/guide/scaling) for the web service. Without it, the service runs a fixed single task (today's behaviour). With it, YOLO registers a scalable target plus a CPU target-tracking policy; add `request-count-per-target` (seeded from a load test) to also scale on per-target request rate.

| Key | Default | Description |
|---|---|---|
| `autoscaling.min` | `1` | Minimum number of tasks. |
| `autoscaling.max` | `4` | Maximum number of tasks. |
| `autoscaling.cpu-utilization` | `65` | Target average CPU % — the always-on policy. |
| `autoscaling.request-count-per-target` | — | Target requests per task per minute (`ALBRequestCountPerTarget`). Omit until you have a load-test number; the policy is created only once it's set. |
| `autoscaling.scale-out-cooldown` | `60` | Seconds between scale-out steps. |
| `autoscaling.scale-in-cooldown` | `300` | Seconds between scale-in steps (kept conservative). |

```yaml
tasks:
  web:
    queue: true
    scheduler: true
    autoscaling:
      min: 1
      max: 6
      cpu-utilization: 65
      request-count-per-target: 1000   # seed from a load test
```

::: warning Bundled scheduler
When the scheduler runs in the same task (`tasks.web.scheduler: true`), scaling to N tasks runs cron N times — every scheduled task would fire on each replica. Every scheduled task **must** use Laravel's `->onOneServer()`, or you should separate the scheduler into its own service. `sync` prints a one-line advisory in this case. See [Scaling → the scheduler caveat](/guide/scaling#the-scheduler-caveat).
:::

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
