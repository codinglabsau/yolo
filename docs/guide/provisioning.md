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
| `yolo sync:compute <env>` | Launch template, ALB, target group, listeners |
| `yolo sync:ci <env>` | CI/CD pipeline |
| `yolo sync:iam <env>` | IAM roles and policies |

## Dry Run

All sync commands support a `--dry-run` flag to preview changes without modifying anything on AWS:

```bash
yolo sync production --dry-run
```

This is a great way to see what resources will be created or modified before committing to any changes.

## Compute Resources

`sync:compute` provisions the following resources:

| Resource | Description |
|---|---|
| Launch template | EC2 instance configuration (AMI, instance type, security groups) |
| Application Load Balancer | Distributes traffic to web instances |
| Target group | Health-checked pool of web instances |
| HTTP listener (port 80) | Forwards HTTP traffic to the target group |
| HTTPS listener (port 443) | Forwards HTTPS traffic with SSL termination |

For standalone apps, the HTTPS listener uses the certificate for your configured `domain`/`apex`. For multi-tenant apps, the first tenant's certificate is used as the default, with additional tenant certificates attached separately via `sync:tenant`.

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
