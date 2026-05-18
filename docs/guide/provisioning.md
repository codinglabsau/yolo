# Provisioning

YOLO creates and manages all AWS resources required to run your application.

## Sync All

Provision everything at once:

```bash
yolo sync <environment>
```

This runs all sync commands in the correct order.

## Individual Sync Commands

You can also provision resources individually:

| Command | Description |
|---|---|
| `yolo sync:network <env>` | VPC, subnets, security groups, SSH keys |
| `yolo sync:standalone <env>` | Standalone app resources |
| `yolo sync:landlord <env>` | Landlord resources (multi-tenancy) |
| `yolo sync:tenant <env>` | Tenant resources (multi-tenancy) |
| `yolo sync:compute <env>` | EC2, autoscaling groups |
| `yolo sync:ci <env>` | CI/CD pipeline |
| `yolo sync:iam <env>` | IAM roles and policies |
| `yolo sync:logging <env>` | Logging and observability infrastructure |

## Dry Run

All sync commands support a `--dry-run` flag to preview changes without modifying anything on AWS:

```bash
yolo sync production --dry-run
```

This is a great way to see what resources will be created or modified before committing to any changes.

## Server Groups

YOLO manages three server groups: **web**, **queue**, and **scheduler**. Each can have its own autoscaling group and CodeDeploy deployment group, or they can be combined onto a single autoscaling group.

### Combined Mode (default for new apps)

The stub manifest ships with `combine: true`, which runs all three workloads on a single autoscaling group:

```yaml
aws:
  autoscaling:
    combine: true
```

YOLO provisions one web autoscaling group; queue workers and scheduler cron run alongside the web server on the same instances. This is the cheapest, simplest starting point — one instance, one ASG, one CodeDeploy group.

### Growing into Separated Mode

When your workloads outgrow a single instance group, the transition is a manifest edit plus one AWS-side step:

```bash
# 1. Remove `combine: true` from your manifest, then:
yolo stage production
yolo sync:ci production
yolo deploy production
```

`stage` provisions new queue and scheduler autoscaling groups. `sync:ci` wires them into CodeDeploy. `deploy` pushes the current build to all three groups. The new queue and scheduler instances launch with the correct (dedicated-group) supervisor configs and immediately start processing.

::: warning Rotate the web ASG after the deploy
Until you rotate the existing web instances, they keep running the supervisor configs they were launched with — including the queue workers and scheduler cron from combined mode. That means **the same queue jobs and scheduled tasks will process on both the old web ASG and the new dedicated ASGs** until the web instances are replaced.

Trigger an AWS instance refresh on the web ASG to replace each instance with one launched from the now-web-only launch template:

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name <web-asg-name> \
  --preferences '{"MinHealthyPercentage": 50, "InstanceWarmup": 60}'
```

Or trigger it from the AWS console under the ASG's **Instance refresh** tab. Either way, the ALB drains connections from old instances before termination, and new instances come up serving the same code but without the queue/scheduler supervisor configs.

**Do not use `yolo stop` against the web ASG to drain workers** — `yolo stop` also stops nginx, which would take the site down.
:::

### Disabling Groups

Set a group to `false` to disable it entirely:

```yaml
aws:
  autoscaling:
    queue: false  # no queue workers
```

No resources will be provisioned and no deployments will target the disabled group. Combine with `combine: true` to colocate only the groups you want.
