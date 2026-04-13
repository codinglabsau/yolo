# Multi-Tenancy

YOLO supports multi-tenant applications where each tenant gets isolated resources.

## Configuration

Define tenants in your manifest under the environment:

```yaml
environments:
  production:
    tenants:
      boating:
        domain: boating-with-yolo.com

      fishing:
        domain: fishing-with-yolo.com
```

Each tenant key must be unique and is used to identify resources throughout YOLO.

## Provisioning

Use the tenant-specific sync commands:

```bash
yolo sync:landlord <environment>   # Provision landlord resources
yolo sync:tenant <environment>     # Provision per-tenant resources
```

Or run `yolo sync <environment>` to provision everything including tenants.

## Domains

See the [Domains](/guide/domains#multi-tenant-apps) page for configuring tenant domains.
