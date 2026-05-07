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
- `with-load-balancing` — Zero-downtime deployments via ALB deregistration.

### `aws.autoscaling.combine`

When `true`, consolidates web, queue, and scheduler onto a single EC2 instance. Useful for small workloads.

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

#### IVS recording

Two optional keys enable S3 recording and webhook delivery for both standard IVS channels and IVS Real-Time stages:

```yaml
aws:
  ivs:
    logging: true
    recording_bucket: your-s3-bucket-name
    recording_webhook_url: https://your-api.example.com/webhooks/ivs/recording
    recording_webhook_secret: your-secret-here
```

| Key | Description |
|---|---|
| `recording_bucket` | S3 bucket name for IVS recordings. Provisions a `RecordingConfiguration` (standard channels) and a `StorageConfiguration` (Real-Time stages), each outputting its ARN for `AWS_IVS_RECORDING_CONFIGURATION_ARN` / `AWS_IVS_STORAGE_CONFIGURATION_ARN`. |
| `recording_webhook_url` | HTTPS endpoint to receive `IVS Recording State Change` / `Recording End` events via EventBridge. |
| `recording_webhook_secret` | Shared secret sent as the `X-Webhook-Secret` header on every delivery. Generate with `openssl rand -hex 32` and set the same value as `IVS_WEBHOOK_SECRET` in the app's environment. |

All three keys are optional — omitting any of them skips the relevant steps without affecting existing resources.

### `mysqldump`

Enable scheduled MySQL backups via `mysqldump`.

### Deploy Commands

- `deploy` — Runs on the scheduler instance during deployment.
- `deploy-queue` — Runs on queue instances.
- `deploy-web` — Runs on web instances.
- `deploy-all` — Runs on all instances.
