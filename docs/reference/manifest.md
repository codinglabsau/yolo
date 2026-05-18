# Manifest

The `yolo.yml` file is the single source of truth for your application's infrastructure configuration.

## Complete Reference

```yaml
name: codinglabs
timezone: UTC

environments:
  production:
    aws:
      account-id:
      region: ap-southeast-2
      vpc:
      internet-gateway:
      public-subnets:
      route-table:
      bucket:
      artefacts-bucket:
      cloudfront:
      alb:
      mediaconvert: false
      ivs: false
      autoscaling:
        combine: true
      ec2:
        instance-type: t3.small
        queue-instance-type:
        scheduler-instance-type:
        octane: false
        nightwatch: false
        key-pair:
        security-group:
      rds:
        subnet:
        security-group:
      codedeploy:
        strategy: without-load-balancing
      sqs:
        depth-alarm-evaluation-periods: 3
        depth-alarm-period: 300
        depth-alarm-threshold: 100

    asset-url: # defaults to aws.cloudfront
    mysqldump: false

    # Standalone apps
    domain: example.com
    apex:

    # Multi-tenant apps
    tenants:
      boating:
        domain: boating-with-yolo.com
      fishing:
        domain: fishing-with-yolo.com

    build:
      - composer install --no-cache --no-interaction --optimize-autoloader --no-progress --classmap-authoritative --no-dev
      - npm ci
      - npm run build
      - rm -rf package-lock.json resources/js resources/css node_modules database/seeders database/factories resources/seeding

    deploy: # runs on scheduler
      - php artisan migrate --force

    deploy-queue: # runs on queue
      -

    deploy-web: # runs on web
      -

    deploy-all: # runs on all instances
      - php artisan optimize
```

## Key Options

### `name`

The application name. Used as a prefix for AWS resource naming.

### `timezone`

Timezone for app version validation. Defaults to `UTC`. Set this to your team's timezone to prevent validation errors at the start of the week.

### `aws.codedeploy.strategy`

- `without-load-balancing` — Faster deployments, brief downtime during restarts.
- `with-load-balancing` — Zero-downtime deployments via ALB deregistration.

### `aws.autoscaling`

YOLO manages three server groups: **web**, **queue**, and **scheduler**. After `yolo stage` runs, the `web`, `queue`, and `scheduler` keys under `aws.autoscaling` are auto-populated with the created autoscaling group names — you never set those manually.

### `aws.autoscaling.combine`

When `true`, consolidates web, queue, and scheduler onto a single autoscaling group. Queue workers and scheduler cron run alongside the web server on the same instance. Useful for small workloads where running three separate instance groups is overkill.

```yaml
aws:
  autoscaling:
    combine: true
```

**This is the default in the stub** — new apps start combined to keep costs and complexity low.

Combined mode runs a single instance with `MinSize=MaxSize=1` on the autoscaling group. The ASG is there for **self-healing, not scaling** — if the instance dies (hardware fault, OOM, agent crash), the ASG automatically launches a replacement and CodeDeploy pushes the latest revision to it. Expect a few minutes of downtime during replacement, but no manual intervention.

If you need to scale beyond a single instance — for HA, throughput, or independent worker scaling — remove `combine: true` and re-stage. YOLO provisions the queue and scheduler autoscaling groups separately, and the web ASG becomes free to grow.

```yaml
# Day 1: one autoscaling group, cheap.
aws:
  autoscaling:
    combine: true

# Day 200: workloads have grown. Remove `combine: true`, re-stage.
aws:
  autoscaling:
    web: my-app-production-web-...
    queue: my-app-production-queue-...
    scheduler: my-app-production-scheduler-...
```

### Disabling Server Groups

Set a server group to `false` to disable it entirely. No resources will be provisioned, no deployments will target it, and no workers will run for that group.

```yaml
aws:
  autoscaling:
    queue: false
```

This can be combined with `combine`:

```yaml
aws:
  autoscaling:
    combine: true
    queue: false  # web + scheduler only
```

Setting `web: false` is valid for appliance-style apps that only need background workers.

### `aws.ec2.octane`

Enable experimental Laravel Octane support.

### `aws.ivs`

Set to `true` to provision a CloudWatch log group, EventBridge rule, and target that captures all `aws.ivs` source events for audit and debugging. Logs use a 14-day default retention.

For finer control, expand into a map:

```yaml
aws:
  ivs:
    logging: true
    log-retention-days: 30
```

`logging` toggles the EventBridge → CloudWatch pipeline; `log-retention-days` overrides the log retention.

### `mysqldump`

Enable scheduled MySQL backups via `mysqldump`.

### Deploy Commands

- `deploy` — Runs on the scheduler instance during deployment.
- `deploy-queue` — Runs on queue instances.
- `deploy-web` — Runs on web instances.
- `deploy-all` — Runs on all instances.
