# Images

With resources provisioned, the next step is to create an Amazon Machine Image (AMI) with Ubuntu OS as the foundation for all server instances.

## Creating an Image

```bash
yolo image:create <environment>
```

Images can be updated over time to bring in improvements like new PHP versions.

## Staging

To prepare a new stage, run:

```bash
yolo stage <environment>
```

This interactive command walks you through updating or replacing the current stage configuration.

### Update vs Create

| Situation | Recommended strategy |
|---|---|
| Update EC2 security group | Update |
| Update EC2 instance type | Update |
| Update EC2 instance profile | Update |
| Update AMI | Create |

When creating a new stage, the `yolo.yml` manifest is updated to point to the new autoscaling groups on the next deployment.

::: tip
Rotating in a new image has no impact on existing traffic until the updated manifest is deployed. The previous deployment continues serving requests and autoscaling as normal.
:::
