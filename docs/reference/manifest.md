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

#### IVS Real-Time recording

Enable S3 composite recording for IVS Real-Time stages:

```yaml
aws:
  ivs:
    logging: true
    recording:
      real_time: true
```

Setting `recording.real_time` to `true` provisions the S3 bucket, IVS `StorageConfiguration`, and `EncoderConfiguration` required for composite recording.

| Key | Description |
|---|---|
| `recording.real_time` | Set to `true` to enable IVS Real-Time composite recording provisioning. Provisions an auto-named S3 bucket (`yolo-{env}-{app}-ivs-realtime-recordings`), a `StorageConfiguration` pointing to that bucket, and an `EncoderConfiguration` (720p30). |

After running `sync:recording`, three values are printed for the app's `.env`:

| Env var | Description |
|---|---|
| `AWS_IVS_REALTIME_RECORDINGS_BUCKET` | Name of the S3 bucket IVS writes recordings to |
| `AWS_IVS_STORAGE_CONFIGURATION_ARN` | ARN passed to `createStage` for automatic participant recording |
| `AWS_IVS_ENCODER_CONFIGURATION_ARN` | ARN passed to `startComposition` to define video resolution and bitrate |

Omitting `recording` entirely skips all recording steps without affecting existing resources.

### `mysqldump`

Enable scheduled MySQL backups via `mysqldump`.

### Deploy Commands

- `deploy` — Runs on the scheduler instance during deployment.
- `deploy-queue` — Runs on queue instances.
- `deploy-web` — Runs on web instances.
- `deploy-all` — Runs on all instances.
