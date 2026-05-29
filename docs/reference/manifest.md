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

    aws:
      account-id: '123456789012'
      region: ap-southeast-2
      # bucket: my-app-bucket     # optional app S3 bucket, injected as AWS_BUCKET

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
- **`aws.region`** (per environment)
- **`aws.account-id`** (per environment)
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

### `asset-url`

Base URL for versioned build assets. Defaults to `aws.cloudfront`. Override only if you serve assets from a different host.

---

## `aws.*`

### `aws.account-id`

**Required.** The AWS account ID to deploy into. Verified against your resolved profile via STS before any change is made.

### `aws.region`

**Required.** The AWS region (e.g. `ap-southeast-2`).

### `aws.bucket`

Name of an app S3 bucket for application storage. Injected into the container as `AWS_BUCKET`.

### `aws.alb`

Name of the Application Load Balancer to use. Defaults to the per-environment shared `yolo-{env}` ALB.

### `aws.artefacts-bucket`

Bucket holding env files and build artefacts. Defaults to `yolo-{env}-artefacts`.

### `aws.alb-logs-bucket`

Bucket for ALB access logs. Defaults to `yolo-{env}-alb-logs`.

### `aws.cloudfront`

The CloudFront distribution domain used as the asset URL.

### `aws.ivs`

Enables IVS (Amazon Interactive Video Service) event logging. Set to `true`, or expand to a map for finer control:

```yaml
aws:
  ivs:
    logging: true
    log-retention-days: 30   # CloudWatch retention (default 14)
```

### `aws.mediaconvert`

MediaConvert role ARN for video transcoding workloads (used with IVS).

### `aws.sqs.*`

Queue depth CloudWatch alarm tuning:

| Key | Default | Description |
|---|---|---|
| `aws.sqs.depth-alarm-threshold` | `100` | Messages before the alarm fires. |
| `aws.sqs.depth-alarm-period` | `300` | Evaluation period in seconds. |
| `aws.sqs.depth-alarm-evaluation-periods` | `3` | Number of periods that must breach. |

### Adopting existing infrastructure (advanced)

By default YOLO creates and names shared networking under `yolo-{env}-…`. To point it at resources you already have, set their id/name. These are escape hatches — most apps never touch them.

| Key | Default | Adopts |
|---|---|---|
| `aws.vpc` | `yolo-{env}` | VPC |
| `aws.internet-gateway` | `yolo-{env}` | Internet gateway |
| `aws.route-table` | `yolo-{env}` | Route table |
| `aws.public-subnets` | derived per env | Public subnet CIDRs |
| `aws.rds.subnet` | — | RDS subnet |
| `aws.rds.security-group` | `yolo-{env}-rds` | RDS security group |
| `aws.ecs.security-group` | `yolo-{env}-{app}` | ECS task security group |
| `ecs.cluster` | `yolo-{env}-{app}` | ECS cluster name (note: top-level under the environment, not under `aws`) |

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
