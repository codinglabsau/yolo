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
