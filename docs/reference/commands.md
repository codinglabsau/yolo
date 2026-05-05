# Commands

## Workflow

| Command | Description |
|---|---|
| `init` | Initialise `yolo.yml` manifest |
| `build <env>` | Prepare application for deployment |
| `deploy <env>` | Build and deploy to AWS |
| `deploy:status <env>` | Track in-progress deployments |

## Infrastructure

| Command | Description |
|---|---|
| `sync <env>` | Run all sync operations |
| `sync:network <env>` | VPC, subnets, security groups, SSH keys |
| `sync:compute <env>` | EC2, autoscaling groups |
| `sync:standalone <env>` | Standalone app resources |
| `sync:landlord <env>` | Multi-tenant landlord resources |
| `sync:tenant <env>` | Per-tenant resources |
| `sync:ci <env>` | CI/CD pipeline |
| `sync:iam <env>` | IAM roles and policies |
| `sync:logging <env>` | Logging and observability infrastructure |
| `sync:storage <env>` | S3 bucket configuration |

## Environment

| Command | Description |
|---|---|
| `env:push <env>` | Push `.env` file to S3 |
| `env:pull <env>` | Pull `.env` file from S3 |

## Images & Instances

| Command | Description |
|---|---|
| `image:create <env>` | Build a new AMI |
| `image:list <env>` | List available AMIs |
| `stage <env>` | Configure or update deployment stage |
| `ec2:list <env>` | List EC2 instances |
| `start <env>` | Start instances |
| `stop <env>` | Stop instances |
| `command <env>` | Execute commands on instances |
| `open <env>` | Open URL in browser |

## Global Options

All sync commands support `--dry-run` to preview changes without modifying AWS resources.

The `build` and `deploy` commands accept `--app-version` to specify the release version.
