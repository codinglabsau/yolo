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
      autoscaling:
        web:
        queue:
        scheduler:
        combine: false
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
- `with-load-balancing` — Zero-downtime deployments via ALB deregistration. Requires an ALB and target group to be provisioned via `sync:compute`.

### `aws.autoscaling.combine`

When `true`, consolidates web, queue, and scheduler onto a single EC2 instance. Only a single autoscaling group is created, and queue workers and scheduler cron run alongside the web server. Useful for small workloads where running three separate instance groups is unnecessary.

```yaml
aws:
  autoscaling:
    combine: true
```

When combined, YOLO skips creating separate autoscaling groups, CodeDeploy deployment groups, and deployments for queue and scheduler — everything runs on the web group.

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

### `mysqldump`

Enable scheduled MySQL backups via `mysqldump`.

### Deploy Commands

- `deploy` — Runs on the scheduler instance during deployment.
- `deploy-queue` — Runs on queue instances.
- `deploy-web` — Runs on web instances.
- `deploy-all` — Runs on all instances.
