# Manifest Reference

`yolo.yml` is the single source of truth for your application's infrastructure. Both `yolo sync` (infrastructure) and `yolo deploy` (code) read from it. This page documents every key.

## A complete example

Every key YOLO understands, in one annotated `yolo.yml`. **Required keys are uncommented; everything else is commented out showing its default**, so you can copy this and uncomment only what you need to change. `yolo init` scaffolds a minimal subset of this — a plain web app whose one container runs all three roles (web, queue worker, scheduler).

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

    # --- Security ---
    # waf: true                  # front the env load balancer with a managed WAF web ACL (env-scoped, off by default)

    # --- Extra IAM for this app's task role (per-app; never reaches another app) ---
    # task-role-policies:
    #   - arn:aws:iam::123456789012:policy/my-app-extra-access
    #   - arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess

    # --- Queue depth alarm tuning ---
    # sqs:
    #   depth-alarm-threshold: 100          # default: 100 — messages before the alarm fires
    #   depth-alarm-period: 300             # default: 300 — evaluation period (seconds)
    #   depth-alarm-evaluation-periods: 3   # default: 3 — periods that must breach

    # Every app runs three roles — web, the queue worker, and the scheduler. With
    # just `web` below they share the one web container; uncommenting `queue` and/or
    # `scheduler` further down extracts them into their own service (see the cascade
    # table under tasks.web.*).
    tasks:
      web:
        cpu: '512'               # default: '512' — Fargate CPU units
        memory: '1024'           # default: '1024' — Fargate memory (MB)
        port: 8000               # default: 8000 — must match the Dockerfile & health check
        enable-execute-command: true   # default: false — enables `yolo run` to attach (gate with MFA)
        # ssr: true                       # default: false — bundle Inertia SSR (needs Node in the Dockerfile)
        # platform: linux/amd64           # default: linux/amd64
        # shutdown-grace-period: 10       # default: 10 — web SIGTERM→SIGKILL window (also the ALB drain)
        # log-retention: 30               # default: 30 — CloudWatch Logs retention (days)
        #
        # health-check:
        #   path: /up                     # default: /up (Laravel's built-in health route)
        #   interval: 10                  # default: 10 (seconds between checks)
        #   timeout: 8                    # default: 8 — tolerant of a slow /up under load
        #   healthy-threshold: 2          # default: 2
        #   unhealthy-threshold: 5        # default: 5 — cushion for a slow-but-alive task
        #   grace-period: 60              # default: 60 (ECS health-check grace period)
        #
        # autoscaling:                    # omit the whole block for a fixed single task
        #   min: 1                        # default: 1
        #   max: 4                        # default: 4
        #   cpu-utilization: 65           # default: 65 — always-on CPU target-tracking policy
        #   request-count-per-target: 1000   # no default — seed from a load test (req/task/min)
        #   scale-out-cooldown: 60        # default: 60
        #   scale-in-cooldown: 300        # default: 300

      # Extract the queue into its own ECS service (scale independently of web).
      # Presence is the opt-in. A standalone queue scales to zero by default —
      # unless it also hosts the scheduler (no `scheduler` block below), where the
      # floor is pinned at min 1 so cron isn't killed when it idles.
      # queue:
      #   min: 0                          # default: 0 — 0 = scale to zero when idle
      #   max: 10                         # default: 10
      #   backlog-per-task: 100           # default: 100 — target messages per running task
      #   cpu: '256'                      # default: '256'
      #   memory: '512'                   # default: '512'
      #   spot: false                     # default: false — true = Fargate Spot (~70% cheaper)
      #   shutdown-grace-period: 70       # default: 70 — let an in-flight job finish on SIGTERM
      #   enable-execute-command: false   # default: false

      # Extract the scheduler into its own pinned-singleton service (always one
      # task; deploys stop-then-start so a rollout never runs two crons), which
      # drops the onOneServer() requirement. Without this block the scheduler rides
      # the standalone queue if there is one, else the web container.
      # scheduler:
      #   cpu: '256'                      # default: '256'
      #   memory: '512'                   # default: '512'
      #   shutdown-grace-period: 10       # default: 10 — wait out an in-flight schedule:run
      #   enable-execute-command: false   # default: false

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

The canonical public domain the app is served on (e.g. `app.example.com`). When it's one half of the apex/`www` pair (the apex itself, or `www.{apex}`), YOLO serves it and 301-redirects the other half to it. Omit for a [headless app](/guide/domains#headless-apps).

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

Name of an app S3 bucket for application storage. Injected into the container as `AWS_BUCKET`, and this app's [ECS task role](#task-role-policies) is automatically granted read+write on it (object get/put/delete + ACL get/set + multipart, plus bucket listing) — so the container reaches its bucket through the role. The grant is scoped to this one bucket. YOLO creates the bucket (Block Public Access on) when it doesn't already exist. On every sync it reconciles the bucket's CORS — a permissive ruleset (origins `*`, methods `GET`/`PUT`/`HEAD`) that lets the browser PUT directly to the bucket via a presigned URL — together with its `yolo:*` tags, surfacing any change in the plan and `--check`. The presigned URL (auth + same-origin), not CORS, is the access gate. Block Public Access is applied at create only and is never reconciled onto an existing bucket, so an adopted bucket (e.g. one carried over from another platform) keeps serving any public objects unchanged.

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

### `waf`

Fronts the environment load balancer with a YOLO-managed [AWS WAF](https://docs.aws.amazon.com/waf/latest/developerguide/what-is-aws-waf.html) web ACL. Set to `true`:

```yaml
waf: true
```

It's **environment scoped** — one web ACL protects every app sharing the ALB — so it's written by [`yolo sync:environment`](/reference/commands#yolo-sync-environment), not `sync:app`. Off by default; provisioning is purely additive once on.

YOLO owns the **policy skeleton** and reconciles it on every sync; you own the **list contents**, which sync never touches:

| YOLO reconciles (declarative) | You manage (console, never reconciled) |
| --- | --- |
| Default action (`Allow`), the allow/block rule wiring, the AWS managed rule groups and their actions, the per-IP rate limit | The CIDRs inside the allow / block IP sets, and any rule you add by hand |

The default skeleton, in priority order:

| Rule | Action | Notes |
| --- | --- | --- |
| Allow IP set | Allow | Seeded **empty** — add known-good IPs (e.g. crawler ranges a managed group might false-positive). |
| Block IP set | Block | Seeded **empty** — the lever for shutting down an abusive source mid-incident. |
| Amazon IP reputation list | Block | Low false-positive; auto-evolves. |
| Known bad inputs | Block | Low false-positive; auto-evolves. |
| Core rule set (CRS) | **Count** | Ships in Count so a new AWS signature can't start blocking live traffic unannounced — promote to Block once you've watched the metrics. |
| SQL injection | **Count** | Same Count-first treatment. |
| Rate limit | Block | ~2000 requests / 5 min **per source IP**. |

The managed groups are referenced **unversioned**, so AWS's signature and IP-reputation updates roll in automatically — the WAF gets better over time without a YOLO change. The IP sets are **create-only**: an IP you add in the console survives every subsequent `sync`. A rule you add by hand (matched by name) is preserved too — YOLO only ever rewrites the rules it owns. `waf` needs a web/ALB environment; it has no effect on a headless app.

### `task-role-policies`

Extra IAM policy ARNs to attach to this app's ECS **task role** — the runtime identity its containers (web, queue and scheduler) assume. YOLO gives every app its own task role, so these grants reach only this app and never another. This is how you let your container call an AWS service YOLO doesn't wire for you (an extra S3 bucket, DynamoDB, Bedrock, …): the role carries the access, so the app authenticates as itself with no credentials to manage.

```yaml
task-role-policies:
  - arn:aws:iam::123456789012:policy/my-app-extra-access   # customer-managed
  - arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess          # AWS-managed
```

The list is reconciled on every `yolo sync`: an ARN you add gets attached, and one you remove gets detached — the role's attachment set is YOLO's to own, so there's no left-behind grant. Each entry must be a customer- or AWS-managed IAM policy ARN; a malformed value fails the sync plan rather than silently dropping the grant. The YOLO baseline policy (ECS Exec channels, this app's SQS queues, SES send, and read+write on the [`bucket`](#bucket) when declared) is always attached and isn't listed here.

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
| `redis` (default) | Nothing new (reuses the Valkey cache) | `SESSION_DRIVER` only | **Requires `cache.store: redis`** (the web-app default) — there's no redis store without it, and YOLO hard-fails if you opt the cache out without re-pinning the session driver. Sessions sit on Laravel's stock `default` connection (**DB 0**), the cache on the `cache` connection (**DB 1**) — same Valkey instance, separate keyspace, so a `cache:clear` never touches sessions. YOLO injects `SESSION_DRIVER=redis` only and leaves `SESSION_CONNECTION` unset; the split is inherited from your stock `config/database.php`, not enforced by YOLO, and relies on cluster-mode-disabled Valkey. A single node has no session HA — a node loss logs users out. See [Sessions and cache share the node, not the keyspace](/guide/provisioning#sessions-and-cache-share-the-node-not-the-keyspace) for the mechanism and caveats. |
| `database` / `cookie` / `file` | Nothing | `SESSION_DRIVER` only | App-managed (pin-only). `cookie` is capped at ~4&nbsp;KB per browser cookie — risky once flashed validation errors are stored. |

On a web app, omitting `session` gives you the `redis` default; set a driver to override it. On a non-web app, `SESSION_DRIVER` is left to your `.env`.

---

## `tasks.web.*`

Declaring `tasks.web` makes the app a Fargate web service. Omit `tasks` entirely for a build-only / headless app with no container.

### Where each role runs

Every app runs three roles — **web** (Octane), the **queue worker**, and the **scheduler** (cron + `schedule:run`). There's no opt-out; the only choice is *where* each runs. By default all three share the one web container — the cheap single-task floor.

To run web in isolation, **extract the worker tier**: add a top-level [`tasks.queue`](#tasks-queue) block and the queue worker *and* the scheduler move out to their own service, leaving web as pure Octane. Add [`tasks.scheduler`](#tasks-scheduler) as well to give cron its own pinned-singleton task. Placement is derived from which blocks are present — there are no `tasks.web.queue` / `tasks.web.scheduler` flags.

| Manifest | web container | worker container | scheduler container |
| --- | --- | --- | --- |
| `web` only | web + queue + scheduler | — | — |
| `web` + `queue` | web | queue + scheduler | — |
| `web` + `queue` + `scheduler` | web | queue | scheduler |

The scheduler rides the worker container (the `web` + `queue` row) rather than getting its own task — there's no point paying for a separate one-task service for cron when the queue is already a managed tier. Because cron then runs on the autoscaling queue, guard scheduled tasks with `->onOneServer()`, or add `tasks.scheduler` for a true singleton. A queue that hosts the scheduler can't scale to zero — cron would stop when it idled — so its floor is pinned at `min: 1` (an explicit `tasks.queue.min: 0` there is rejected).

| Key | Default | Description |
|---|---|---|
| `tasks.web.port` | `8000` | Container port. Must match the Dockerfile's exposed port and the health check. |
| `tasks.web.cpu` | `'512'` | Fargate CPU units. |
| `tasks.web.memory` | `'1024'` | Fargate memory (MB). |
| `tasks.web.platform` | `linux/amd64` | Docker build platform. |
| `tasks.web.enable-execute-command` | `false` | Enable ECS Exec so [`yolo run`](/reference/commands#yolo-run) can attach. Gate access with MFA on your IAM. |
| `tasks.web.ssr` | `false` | Run Inertia's SSR renderer (`inertia:start-ssr`, a Node process on `127.0.0.1:13714`) **bundled** in the web container, so PHP server-renders your Vue pages. `true`, or an object to override its `shutdown-grace-period`. SSR is always bundled — never its own service. Needs a Node runtime in your Dockerfile and an SSR bundle from `npm run build`; YOLO injects `INERTIA_SSR_ENABLED=true` unless your `.env` sets it. See [Inertia SSR](/guide/images#inertia-ssr). |
| `tasks.web.shutdown-grace-period` | `10` | Seconds the web process gets on `SIGTERM` before `SIGKILL`. It's also the ALB drain window and the container `stopTimeout`. See [graceful shutdown](/guide/images#graceful-shutdown). |
| `tasks.web.log-retention` | `30` | CloudWatch Logs retention (days). Must be a valid CloudWatch retention value. |

YOLO manages the ECS task and execution roles for you — the task role is per-app (extend it with [`task-role-policies`](#task-role-policies)); the execution role is shared per environment.

### `tasks.web.health-check.*`

ALB target-group health check. The path defaults to Laravel's built-in [`/up` health route](https://laravel.com/docs/deployment#the-health-route), which returns `200` only once the framework boots without exceptions (and `500` otherwise) — so a broken boot fails the check. Requests to it also dispatch Laravel's `Illuminate\Foundation\Events\DiagnosingHealth` event, so you can add a listener that checks your database or cache and throws to mark the app unhealthy.

The other defaults are tuned to avoid false-positive failures on a Laravel/Octane app under load: when the FrankenPHP worker pool is saturated the `/up` probe answers slowly (6–7s) rather than failing, so the timeout sits at `8`s — a slow-but-alive task stays in service — with a roomier `5`-failure unhealthy threshold for cushion. A genuine deadlock (no response / 30s+) still trips within ~a minute. Capacity is [autoscaling](/guide/scaling)'s job, not the health check's. Override any field per app if you need to:

| Key | Default | Description |
|---|---|---|
| `health-check.path` | `/up` | Path the ALB requests — defaults to Laravel's built-in `/up` health route. Keep it on a route that exercises PHP so a broken boot still fails the check. |
| `health-check.interval` | `10` | Seconds between checks. |
| `health-check.timeout` | `8` | Seconds before a check times out. Must stay below the interval. |
| `health-check.healthy-threshold` | `2` | Consecutive successes to mark healthy. |
| `health-check.unhealthy-threshold` | `5` | Consecutive failures to mark unhealthy. |
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
    autoscaling:
      min: 1
      max: 6
      cpu-utilization: 65
      request-count-per-target: 1000   # seed from a load test
```

::: warning Bundled scheduler
A plain web app bundles the scheduler in the web container, so scaling to N tasks runs cron N times — every scheduled task would fire on each replica. Every scheduled task **must** use Laravel's `->onOneServer()`, or extract the scheduler into its own service ([`tasks.scheduler`](#tasks-scheduler)). `sync` prints a one-line advisory whenever the scheduler is bundled into an autoscaling host (the web task, or the standalone queue, which always autoscales). See [Scaling → the scheduler](/guide/scaling#the-scheduler).
:::

---

## `tasks.queue.*`

A top-level `tasks.queue` block extracts the queue worker into its **own** ECS service, so it scales independently of web. Presence is the opt-in — an empty block (`queue:`) gives a scale-to-zero worker on default sizing. Without it, the worker stays bundled in the web container (see [Where each role runs](#where-each-role-runs)).

A standalone queue **scales to zero by default** (`min: 0`): zero tasks — and zero compute cost — when the queue is empty, scaling up on backlog. The trade-off is a ~30–60s Fargate cold start on the first message after idle, so it suits bursty, latency-tolerant work. For latency-sensitive jobs that must start instantly, leave the queue bundled in the warm web container or set a standing floor (`min: 1`). One caveat: when the queue also hosts the scheduler (a `tasks.queue` block with no [`tasks.scheduler`](#tasks-scheduler)), it can't scale to zero — the floor is pinned at `min: 1`, and an explicit `tasks.queue.min: 0` is rejected.

Scaling is **backlog-per-task** target tracking (`ApproximateNumberOfMessagesVisible / RunningTaskCount`, CloudWatch metric math — no Lambda). A scale-to-zero queue (`min: 0`) also gets a step-scaling alarm that lifts it 0→1 the instant a message arrives (target tracking can't divide by zero running tasks).

| Key | Default | Description |
|---|---|---|
| `tasks.queue.min` | `0` | Minimum tasks. `0` = scale to zero when idle. |
| `tasks.queue.max` | `10` | Maximum tasks. |
| `tasks.queue.backlog-per-task` | `100` | Target visible messages per running task — the scale-out trigger. |
| `tasks.queue.cpu` | `'256'` | Fargate CPU units. |
| `tasks.queue.memory` | `'512'` | Fargate memory (MB). |
| `tasks.queue.spot` | `false` | `true` runs the queue on Fargate Spot (~70% cheaper, interruptible — fine for a worker whose jobs retry). |
| `tasks.queue.shutdown-grace-period` | `70` | Seconds the worker gets on `SIGTERM` to finish its in-flight job before `SIGKILL`. |
| `tasks.queue.enable-execute-command` | `false` | Enable ECS Exec on the queue service. |

See [Scaling → the queue](/guide/scaling#the-queue-scale-to-zero).

---

## `tasks.scheduler.*`

A top-level `tasks.scheduler` block extracts the scheduler (busybox `crond` firing `schedule:run`) into its **own** ECS service, pinned at exactly one task — a genuine singleton, so `->onOneServer()` is no longer required. It deploys **stop-then-start** (`minimumHealthyPercent: 0` / `maximumPercent: 100`) so a rollout never briefly runs two crons; a missed cron minute is harmless, a double-run isn't. Without this block the scheduler rides the standalone queue if there is one, else the web container (see [Where each role runs](#where-each-role-runs)).

The scheduler never scales (a per-minute cron can't tolerate a cold start), so it has no `min`/`max`.

| Key | Default | Description |
|---|---|---|
| `tasks.scheduler.cpu` | `'256'` | Fargate CPU units (the scheduler is light — the smallest tier is usually plenty). |
| `tasks.scheduler.memory` | `'512'` | Fargate memory (MB). |
| `tasks.scheduler.shutdown-grace-period` | `10` | Seconds to wait out an in-flight `schedule:run` on `SIGTERM`. Long-running work belongs on the queue, not the cron tick. |
| `tasks.scheduler.enable-execute-command` | `false` | Enable ECS Exec on the scheduler service. |

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
