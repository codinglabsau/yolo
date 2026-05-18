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

YOLO manages three server groups: **web**, **queue**, and **scheduler**. By default, each gets its own autoscaling group and CodeDeploy deployment group.

### Combined Mode

For smaller workloads, set `combine: true` in your manifest to run all three on a single autoscaling group:

```yaml
aws:
  autoscaling:
    combine: true
```

YOLO will skip creating separate resources for queue and scheduler — workers and cron run on the web instances instead.

### Disabling Groups

Set a group to `false` to disable it entirely:

```yaml
aws:
  autoscaling:
    queue: false  # no queue workers
```

No resources will be provisioned and no deployments will target the disabled group.
