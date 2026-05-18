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

When your workloads outgrow a single instance group, remove `combine: true` from the manifest and re-stage. YOLO provisions separate autoscaling groups for queue and scheduler and wires them up automatically — no manual intervention beyond the manifest edit.

### Disabling Groups

Set a group to `false` to disable it entirely:

```yaml
aws:
  autoscaling:
    queue: false  # no queue workers
```

No resources will be provisioned and no deployments will target the disabled group. Combine with `combine: true` to colocate only the groups you want.
