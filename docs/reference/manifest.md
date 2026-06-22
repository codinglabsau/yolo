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
    # alb: yolo-production                           # default: yolo-{env} — shared ALB to attach to

    # --- Cache & session (web apps default to these; uncomment only to override) ---
    # cache:
    #   store: redis             # default; file/database/array to opt out of the shared Valkey
    # session:
    #   driver: redis            # default (sessions live on the Valkey cluster); database/cookie/file to change the session backend

    # --- YOLO-provisioned services this app consumes ---
    # services:            # bare capability names only — service shape is hardcoded or lives in the environment manifest
    #   - ivs
    #   - mediaconvert
    #   - rekognition

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
        autoscaling: true              # REQUIRED for web — true (min 1, max 5) | false (fixed single task) | a block
        # autoscaling:                    # …or tune it (web min must be ≥ 1)
        #   min: 1                        # default: 1
        #   max: 5                        # default: 5
        #   cpu-utilization: 65           # default: 65 — the CPU safety-net policy
        #   scale-out-cooldown: 60        # default: 60
        #   scale-in-cooldown: 300        # default: 300
        #   # request concurrency is the default signal (derived from task memory); burst
        #   # (~10s spike detection) is unconditional for octane — neither has a knob

      # Extract the queue into its own ECS service (scale independently of web). Like
      # web, a standalone queue must be a config map that declares `autoscaling` — there's
      # no bare `queue: true` shorthand. `false` switches the worker off entirely (jobs run
      # inline, QUEUE_CONNECTION=sync) and tears the SQS queue + its depth alarm down. Set
      # autoscaling.min: 0 to scale to zero when idle — except when it also hosts the
      # scheduler (no `scheduler` block below), where min 0 is rejected so cron isn't
      # killed when it idles.
      # queue:
      #   autoscaling:                    # required — true | false | a { min, max } block
      #     min: 0                        # 0 = scale to zero when idle (floor defaults to 1)
      #     max: 5                        # default: 5
      #     backlog-per-task: 100         # default: 100 — target messages per running task
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
      #   shutdown-grace-period: 115      # default: 115 — the in-flight schedule:run gets the whole stop window
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

### `services`

The YOLO-provisioned services this app consumes — a list of bare capability names:

```yaml
services:
  - ivs
```

See the [Services guide](/guide/services) for the full model and the need-to-know for each one; this is the manifest-key summary.

| Service | What consuming it gives this app |
|---|---|
| `ivs` | The app's ECS task role is granted IVS access (`ivs:*` — channels and stream keys are created by the app at runtime, so there's nothing stable to scope to), and the app's CloudWatch dashboard gains the IVS logs panel |
| `typesense` | The app uses the environment's shared [Typesense search cluster](/guide/services#typesense-the-environment-s-search-cluster) — the cluster is provisioned by the environment while its env-manifest entry stands, independent of whether any app currently consumes it. No runtime IAM: the app talks to the cluster over HTTP. `sync:app` opens the app's private path (its task SG onto the search API port) and **mints the app two keys scoped to its own `{prefix}*` collections** — a server-side key (all actions) and a browser search-only key (`documents:search`) — written into the app's environment-side `.env` (`env/.env.{app}` in the env config bucket — a YOLO-owned per-app secret channel kept out of the app's developer `.env`, which the admin tier running `sync` is fenced from). The build merges that file in and injects `SCOUT_DRIVER=typesense`, `SCOUT_PREFIX`, the private Cloud Map node addresses for indexing (`TYPESENSE_HOST/PORT/PROTOCOL` + the full `TYPESENSE_NODES` list — server-side indexing rides the VPC, never the ALB/WAF), and the public search host for browser-direct search (`TYPESENSE_SEARCH_HOST/PORT/PROTOCOL`, on `search.{domain}`). The app's CloudWatch dashboard gains the search panels |
| `mediaconvert` | A per-app IAM role for AWS Elemental MediaConvert to assume is provisioned, its computed ARN is baked into the build as `AWS_MEDIACONVERT_ROLE_ID`, the task role is granted the job operations plus `iam:PassRole` locked to that one role and to MediaConvert itself, and the app's CloudWatch dashboard gains a MediaConvert jobs panel. App-side only — jobs run on the account's default on-demand queue, so there is no environment-manifest half |
| `rekognition` | The app's ECS task role is granted Rekognition access (`rekognition:*` — the detection APIs are resource-less, operating on request payloads or S3 objects read with the caller's own credentials, so reads of the app [`bucket`](#bucket) ride its existing grant), and the app's CloudWatch dashboard gains a Rekognition requests panel. App-side only — a pure pay-per-call API, nothing is provisioned, so there is no environment-manifest half |

An entry is deliberately just a name — *this app uses ivs*: all service **shape** (sizing, versions, retention) is either hardcoded or belongs to [the environment manifest](#the-environment-manifest-yolo-environment-environment-yml), never the app manifest — so two apps can never declare competing configuration for shared infrastructure. Unknown names, duplicate entries, or anything other than a flat list hard-fail validation. The list is also **published**: every `deploy` and `sync:app` writes it (`apps/{app}.yml` — name + services) into the [env config bucket](/guide/provisioning#the-environment-declaration), so the environment always knows which apps are using which shared services.

The corresponding env-shared infrastructure is the environment manifest's side of the contract — e.g. the IVS event-logging pipeline (one `/aws/ivs/yolo-{env}` log group + EventBridge rule per environment, because the `aws.ivs` event stream is account-wide) is provisioned by `sync:environment` while `yolo-environment-{environment}.yml` **declares** `services.ivs` — [the service lifecycle](/guide/services#the-service-lifecycle). Using an env-backed service the environment doesn't declare is a **hard error** at `build`, `deploy` and `sync:app` (declare it, or take it out of `yolo.yml`); when an app stops using a service, its app-side resources (e.g. the MediaConvert role) melt away on the next sync. Defaulted framework backends ([`cache`](#cache), [`session`](#session)) deliberately stay separate keys — `services` is for opt-in capabilities only.

::: tip No `waf` key
The [web application firewall](/guide/provisioning#web-application-firewall) is a **compulsory** environment resource — every environment with a load balancer gets one automatically, so there's nothing to configure here. Day-to-day tuning happens in its allow/block IP sets, not the manifest.
:::

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

## `database`

Declares the RDS instance or Aurora cluster the app connects to, so YOLO can chart it — the **Database** section of the app's CloudWatch dashboard and the **Database** tab of [`yolo status`](/reference/commands#yolo-status) (CPU, connections, freeable memory, read/write latency). Entirely optional: omit it and the database panels are simply dropped.

YOLO doesn't manage your database, so it can't discover the identifier on its own. It's declared in the manifest — rather than read from `DB_HOST` in the app's `.env` — because the dashboard is written by `yolo sync` under the admin tier, which is deliberately barred from reading app secrets; a manifest value is read identically by every tier, so the dashboard never drifts between who writes it and who checks it.

A single flat value, taken two ways:

```yaml
# A plain RDS instance — the bare identifier (its DBInstanceIdentifier):
database: my-app-db

# An Aurora cluster — paste the full cluster endpoint host; YOLO detects the
# cluster and charts the writer (DBClusterIdentifier + Role=WRITER), which
# follows failovers. An RDS Proxy / non-RDS host is skipped.
database: my-app.cluster-cabc123.ap-southeast-2.rds.amazonaws.com
```

A bare value (no `.rds.amazonaws.com`) is charted as a plain instance; a full endpoint hostname self-describes whether it's an Aurora cluster or an instance. For a plain RDS instance the short name is enough; for Aurora use the endpoint so the writer metrics are charted.

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

## `budget`

An advisory monthly spend target for the app. YOLO **never enforces** it — it never acts on your account on its own. The budget is read by [`yolo status:budget`](/reference/commands#yolo-status-budget) (spend vs cap) and by the [`/yolo` skill](/guide/the-yolo-skill), which weights its recommendations by the `strategy`.

```yaml
budget:
  amount: 100          # USD per month (advisory cap)
  strategy: balanced   # lean | balanced | conservative (default: balanced)
```

| Key | Default | Description |
|---|---|---|
| `budget.amount` | — | The monthly spend target in USD. Optional; omit it and `status:budget` reports spend with "no budget set". |
| `budget.strategy` | `balanced` | How aggressively the `/yolo` skill should trade cost against headroom — `lean` (cost-first), `balanced`, or `conservative` (headroom-first). |

Spend is read from AWS Cost Explorer via the app's `yolo:app` tag; it shows once that tag is [activated as a cost-allocation tag](/reference/commands#yolo-status-budget) in Billing.

The budget block is **two-tier**: the same `budget` shape can also be declared in the [environment manifest](#the-environment-manifest-yolo-environment-environment-yml), where it caps the whole environment (every app + shared infra, attributed via the `yolo:environment` tag) and is reported by [`status:environment`](/reference/commands#yolo-status-environment). App-tier `budget` lives in `yolo.yml`; env-tier `budget` in `yolo-environment-<env>.yml`.

---

## `tasks.web.*`

Declaring `tasks.web` as a config object makes the app a Fargate web service; it **must declare** [`autoscaling`](#tasks-web-autoscaling) — there's no implicit default, and the bare `tasks.web: true` shorthand isn't accepted (a scalar tier has nowhere to state its scaling behaviour). `tasks.web: false` — or omitting `tasks` entirely — is a build-only / headless app with no web container.

### Where each role runs

Every app runs three roles — **web**, the **queue worker**, and the **scheduler** (cron + `schedule:run`). The web tier runs Octane (FrankenPHP worker mode) by default; set [`tasks.web.octane: false`](#tasks-web) to run FrankenPHP in classic mode instead (per-request boot, no resident app). By default the queue worker and scheduler both share the one web container — the cheap single-task floor. Each can be **extracted** into its own service, or **switched off** entirely.

To run web in isolation, **extract the worker tier**: add a top-level [`tasks.queue`](#tasks-queue) block and the queue worker *and* the scheduler move out to their own service, leaving the web container running just the web server. Add [`tasks.scheduler`](#tasks-scheduler) as well to give cron its own pinned-singleton task. Placement is derived from which blocks are present — there are no `tasks.web.queue` / `tasks.web.scheduler` flags.

Each block is **`true | false | {config}`** (the same boolean-or-object form as [`tasks.web.ssr`](#tasks-web)): `true` extracts the role with default sizing, a config object extracts it with overrides, and `false` switches it off so the role runs **nowhere** — neither bundled nor extracted. An empty block (`queue:`) or empty object (`{}`) is rejected — state the intent explicitly. The **`queue`** block is the one exception to bare `true`: like web, a standalone queue must be a config object that declares [`autoscaling`](#tasks-queue) (web and queue both need a definitive scaling decision). Only **`scheduler`** — a pinned singleton that never scales — keeps the bare `true` shorthand.

Placement is reconciled, not just applied: if an app was running an extracted queue or scheduler service and you later **bundle the role back in** (remove the block) or **switch it off** (`false`), the next `sync` tears the now-orphaned ECS service down — and for the queue, its scalable target, scaling policies and scale-to-zero alarm with it — so a dropped block never strands a live service. Switching the **queue off** (`false`) goes further: because jobs then run inline (`QUEUE_CONNECTION=sync`) and nothing is ever enqueued, `sync` also tears down the app's SQS queue and its depth alarm, and the CloudWatch dashboard drops its queue panel (single-tenant apps; a multi-tenant app's per-tenant queues are torn down by `destroy:app` instead).

In the placement table below, `queue: true` is shorthand for "queue extracted" — the real manifest writes `queue: { autoscaling: … }`; only `scheduler: true` is literal.

| Manifest | web container | worker container | scheduler container |
| --- | --- | --- | --- |
| `web` only | web + queue + scheduler | — | — |
| `web` + `queue: true` | web | queue + scheduler | — |
| `web` + `queue: true` + `scheduler: true` | web | queue | scheduler |
| `web` + `queue: false` | web (no worker) | — | — |
| `web` + `scheduler: false` | web + queue (no cron) | — | — |

The scheduler rides the worker container (the `web` + `queue` row) rather than getting its own task — there's no point paying for a separate one-task service for cron when the queue is already a managed tier. Because cron then runs on the autoscaling queue, guard scheduled tasks with `->onOneServer()`, or add `tasks.scheduler` for a true singleton. A queue that hosts the scheduler can't scale to zero — cron would stop when it idled — so its floor stays at `1` (an explicit `tasks.queue.autoscaling.min: 0` there is rejected).

**Disabling the queue (`queue: false`)** means no worker runs anywhere, so jobs can't be processed off-request: YOLO bakes `QUEUE_CONNECTION=sync` (jobs run inline at dispatch) and **fails the build** if your `.env` pins it to anything else, rather than ship an app that black-holes queued work. **Disabling the scheduler (`scheduler: false`)** stops `schedule:run` running anywhere — framework and package maintenance that rides cron (model pruning, `auth:clear-resets`, Telescope/Pulse pruning, …) silently stops, so `sync` surfaces a warning. Reach for these only when the app genuinely has no background work.

| Key | Default | Description |
|---|---|---|
| `tasks.web.octane` | `true` | Run the web tier on Octane (FrankenPHP **worker mode**) via `octane:start`. Set `false` to run FrankenPHP in **classic mode** (`frankenphp php-server` — per-request boot, no resident app) for an app that isn't Octane-safe yet. Same image and port either way; only the launch command differs, and the build's [Octane preflight](/guide/building-and-deploying) is skipped (classic mode needs no `laravel/octane`). |
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

The other defaults are tuned to avoid false-positive failures on a Laravel/Octane app under load: when the FrankenPHP worker pool is saturated the `/up` probe answers slowly (6–7s) rather than failing, so the timeout sits at `8`s — a slow-but-alive task stays in service — with a roomier `5`-failure unhealthy threshold for cushion. A genuine deadlock (no response / 30s+) still trips within ~a minute. Capacity is [autoscaling](/guide/scaling)'s job, not the health check's. (An app on classic mode — [`tasks.web.octane: false`](#tasks-web) — boots per request rather than saturating a worker pool, so its latency shape differs, but the same generous defaults apply.) Override any field per app if you need to:

| Key | Default | Description |
|---|---|---|
| `health-check.path` | `/up` | Path the ALB requests — defaults to Laravel's built-in `/up` health route. Keep it on a route that exercises PHP so a broken boot still fails the check. |
| `health-check.interval` | `10` | Seconds between checks. |
| `health-check.timeout` | `8` | Seconds before a check times out. Must stay below the interval. |
| `health-check.healthy-threshold` | `2` | Consecutive successes to mark healthy. |
| `health-check.unhealthy-threshold` | `5` | Consecutive failures to mark unhealthy. |
| `health-check.grace-period` | `60` | Seconds after task start before health checks count (the ECS health-check grace period). |

### `tasks.web.autoscaling.*`

[Application Auto Scaling](/guide/scaling) is **required** for the web service — `autoscaling` is `true | false | {config}` and the key can't be omitted (nor can web use the bare `tasks.web: true` shorthand). **`autoscaling: true`** takes the defaults (`min: 1`, `max: 5`); **`autoscaling: false`** pins a fixed single task (no scalable target); a `{min, max, …}` object sets bespoke bounds. An empty object (`{}`) is rejected — write `true` or `false`. Web **`min` must be ≥ 1** (it serves traffic and can't idle to zero). With autoscaling on, YOLO scales on **request concurrency** — the default, leading signal, with its target derived from the task's memory so there's nothing to tune — and composes a **CPU** policy alongside as a safety net. The only knobs are the bounds and cooldowns.

| Key | Default | Description |
|---|---|---|
| `autoscaling` | *(required)* | `true` for scaling on defaults, `false` for a fixed single task, or an object to tune. No implicit default — must be declared. |
| `autoscaling.min` | `1` | Minimum number of tasks (must be ≥ 1). |
| `autoscaling.max` | `5` | Maximum number of tasks. |
| `autoscaling.cpu-utilization` | `65` | Target average CPU % — the safety-net policy composed alongside concurrency. |
| `autoscaling.scale-out-cooldown` | `60` | Seconds between scale-out steps (both policies). |
| `autoscaling.scale-in-cooldown` | `300` | Seconds between scale-in steps, both policies (kept conservative). |

There's no `burst` knob: real-time [burst scale-out](/guide/scaling#faster-scale-out-burst) (a high-res worker-saturation alarm + step policy, ~10s spike detection) is just part of how web autoscaling works — provisioned with the scalable target, like the concurrency and CPU policies (a classic-mode tier never emits the signal, so it's a no-op there).

The request-concurrency policy itself has no manifest knob: its target is `floor(memory / 30)` workers per task at 70% utilisation (see [Scaling](/guide/scaling#how-the-concurrency-target-is-derived)).

```yaml
tasks:
  web:
    autoscaling:
      min: 1
      max: 6
      cpu-utilization: 65
```

::: warning Bundled scheduler
A plain web app bundles the scheduler in the web container, so scaling to N tasks runs cron N times — every scheduled task would fire on each replica. Every scheduled task **must** use Laravel's `->onOneServer()`, or extract the scheduler into its own service ([`tasks.scheduler`](#tasks-scheduler)). The `sync` plan lists an advisory under its **Warnings** section whenever the scheduler is bundled into an autoscaling host (the web task, or a standalone queue — both must declare autoscaling). See [Scaling → the scheduler](/guide/scaling#the-scheduler).
:::

---

## `tasks.queue.*`

`tasks.queue` is a **config object** that extracts the queue worker into its **own** ECS service (so it scales independently of web), or **`false`** to switch the worker off entirely (it runs nowhere, and YOLO enforces `QUEUE_CONNECTION=sync` — see [Where each role runs](#where-each-role-runs)); omitting the block leaves the worker bundled in the web container. Like web, a standalone queue **must declare `autoscaling`** — there's no bare `queue: true` shorthand, and an empty block (`queue:`) or empty object (`{}`) is rejected.

`autoscaling` is the same `true | false | {min, max, backlog-per-task}` knob as web: `true` takes the defaults (`min: 1`, `max: 5`), `false` pins a fixed single task (no scalable target, no backlog policy). Set **`autoscaling.min: 0`** to opt into **scale to zero**: zero tasks — and zero compute cost — when the queue is empty, at the cost of a ~30–60s Fargate cold start on the first message after idle (so it suits bursty, latency-tolerant work). The queue `min` may be `0` (unlike web); but when the queue also hosts the scheduler (a `tasks.queue` block with no [`tasks.scheduler`](#tasks-scheduler)) it can't scale to zero — cron would stop — so an explicit `tasks.queue.autoscaling.min: 0` is rejected there.

Scaling is **backlog-per-task** target tracking (`ApproximateNumberOfMessagesVisible / RunningTaskCount`, CloudWatch metric math — no Lambda). A scale-to-zero queue (`autoscaling.min: 0`) also gets a step-scaling alarm that lifts it 0→1 the instant a message arrives (target tracking can't divide by zero running tasks).

| Key | Default | Description |
|---|---|---|
| `tasks.queue.autoscaling` | *(required)* | `true` for scaling on defaults, `false` for a fixed single task, or an object to tune. No implicit default — must be declared. |
| `tasks.queue.autoscaling.min` | `1` | Minimum tasks. `0` = scale to zero when idle. |
| `tasks.queue.autoscaling.max` | `5` | Maximum tasks. |
| `tasks.queue.autoscaling.backlog-per-task` | `100` | Target visible messages per running task — the scale-out trigger. |
| `tasks.queue.cpu` | `'256'` | Fargate CPU units. |
| `tasks.queue.memory` | `'512'` | Fargate memory (MB). |
| `tasks.queue.spot` | `false` | `true` runs the queue on Fargate Spot (~70% cheaper, interruptible — fine for a worker whose jobs retry). |
| `tasks.queue.shutdown-grace-period` | `70` | Seconds the worker gets on `SIGTERM` to finish its in-flight job before `SIGKILL`. |
| `tasks.queue.enable-execute-command` | `false` | Enable ECS Exec on the queue service. |

See [Scaling → the queue](/guide/scaling#the-queue-scale-to-zero).

---

## `tasks.scheduler.*`

`tasks.scheduler` is **`true | false | {config}`**. `true` (or a config object) extracts the scheduler ([supercronic](https://github.com/aptible/supercronic) firing `schedule:run`) into its **own** ECS service, pinned at exactly one task — a genuine singleton, so `->onOneServer()` is no longer required. It deploys **stop-then-start** (`minimumHealthyPercent: 0` / `maximumPercent: 100`) so a rollout never briefly runs two crons; a missed cron minute is harmless, a double-run isn't. `false` switches cron off entirely — `schedule:run` runs nowhere, so framework/package maintenance that rides the scheduler silently stops (`sync` warns); use it only for an app with no scheduled work. Omitting the block leaves the scheduler riding the standalone queue if there is one, else the web container (see [Where each role runs](#where-each-role-runs)). An empty block (`scheduler:`) or empty object (`{}`) is rejected — write `true` for default sizing.

The scheduler never scales (a per-minute cron can't tolerate a cold start), so it has no `min`/`max`.

| Key | Default | Description |
|---|---|---|
| `tasks.scheduler.cpu` | `'256'` | Fargate CPU units (the scheduler is light — the smallest tier is usually plenty). |
| `tasks.scheduler.memory` | `'512'` | Fargate memory (MB). |
| `tasks.scheduler.shutdown-grace-period` | `115` | Seconds an in-flight `schedule:run` gets to finish after `SIGTERM` — supercronic stops launching new runs immediately, and its stop overlaps the other programs', so the default hands the run the whole stop window (Fargate's 120s `stopTimeout` cap minus buffer). A run cut off at the wire should self-heal on a later tick; routinely long work still belongs on the queue. |
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

---

## The environment manifest (`yolo-environment-{environment}.yml`)

`yolo.yml` declares what one **app** needs; the environment has a declaration of its own. `yolo-environment-{environment}.yml` (e.g. `yolo-environment-production.yml` — the environment is in the filename, so a pulled copy can never be pushed at the wrong environment) lives in the env config bucket (`yolo-{account-id}-{env}-config`), not in any app's repo — it's seeded by the environment's first `sync` and from then on owned by the operator, edited through the [`environment:manifest:pull` / `environment:manifest:push`](/reference/commands#yolo-environment-manifest-pull) commands. Every `sync:environment` pulls it fresh from S3 and reconciles toward it, from any app repo.

```yaml
domain: example.com.au   # the env's canonical domain for shared-service ingress
services: {}             # env-shared services — the extension point for what sync:environment provisions
```

| Key | Purpose |
|---|---|
| `domain` | The environment's canonical domain for shared-service hostnames (e.g. `search.{domain}`). Distinct from any app's `domain` — shared services are served on the *environment's* name, reachable from every app regardless of their own domains. |
| `services` | The env-shared services this environment runs — a map of service ⇒ config (`services.ivs: {}`). The declaration is the whole trigger of [the service lifecycle](/guide/services#the-service-lifecycle): `sync:environment` provisions a declared service (independent of any consumer) and plans its teardown once the entry is removed; a declared service no running app uses is flagged as **idle** (a plan warning), not torn down. `environment:manifest:push` refuses to remove a service apps still use. Each entry is a map (never a scalar or list); its allowed keys come from the service's definition. |
| `services.typesense` | The environment's [Typesense search cluster](/guide/services#typesense-the-environment-s-search-cluster). `version` (the `typesense/typesense` image tag) is required — an environment never runs an implicit search engine version. `nodes`, `cpu` and `memory` follow the [`tasks.*` conventions](#tasks-web): optional, defaulting to `3` nodes at `'256'`/`'1024'` each. `nodes` accepts `3` or `5` — five spreads read load wider and survives two losses; an even count pays for an extra node without gaining the ability to lose another one, and a single node would lose its search data whenever the task is replaced, so neither is offered. `services: { typesense: { version: "30.2" } }` is a complete entry. A version bump or resize is a manifest edit + `sync:environment` — the nodes roll one at a time. |

Like `yolo.yml`, the file is validated against a strict allow-list — an unrecognised key hard-fails both `environment:manifest:push` (before upload) and any sync that reads it. The allow-list is compiled into each release, so adding a new env-manifest key means updating `codinglabsau/yolo` in the environment's app repos **before** pushing the key — an older binary hard-fails (with an upgrade hint) rather than silently ignoring declarations it doesn't know. See [The environment declaration](/guide/provisioning#the-environment-declaration) for the model.
