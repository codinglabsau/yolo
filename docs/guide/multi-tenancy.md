# Multi-Tenancy

YOLO supports multi-tenant applications where each tenant is served on its own domain and gets its own isolated queue. One container image and one ECS service serve every tenant; the per-tenant resources are the routing and the queues.

## Configuration

Declare tenants under the environment, keyed by a unique tenant id:

```yaml
environments:
  production:
    account-id: '123456789012'
    region: ap-southeast-2
    tenants:
      acme:
        domain: acme.example.com
      globex:
        domain: globex-with-yolo.com
    tasks:
      web:
        autoscaling: true
      queue:
        autoscaling: true
      scheduler: true
```

The tenant id (`acme`, `globex`) identifies that tenant's resources throughout YOLO. Each tenant follows the same domain rules as a solo app — set only its `domain`, and YOLO derives the tenant's apex (its hosted zone) from it, subdomains included (see [Domains](/guide/domains)).

::: warning
A multi-tenant app must not set `domain` at the **environment** level — it belongs to each tenant. Declaring `tenants` is what puts the app in multi-tenant mode.
:::

When you answer "yes" to the multi-tenant prompt in `yolo init`, it scaffolds a `tenants` block and sets up landlord/tenant migration hooks for you:

```yaml
deploy:
  - php artisan migrate --path=database/migrations/landlord --force
  - php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"
```

## What gets provisioned

`yolo sync` (or `sync:app`) fans the per-tenant steps out across every tenant:

- A **landlord** SQS queue and depth alarm for shared/central work.
- A **per-tenant** SQS queue and depth alarm for each tenant.
- Per-tenant DNS records, pointed at the shared load balancer, are UPSERTed during `yolo deploy`.

Certificates attach per tenant via SNI on the environment's shared HTTPS listener, so adding a tenant doesn't disturb the others.

## Single-tenant operations

Use `--tenant=<id>` to narrow the per-tenant steps to one tenant — useful when onboarding a new tenant or running a single-tenant cutover without touching the rest:

```bash
yolo sync:app production --tenant=acme
```

There is no `sync:tenant` or `deploy:tenant` verb — tenancy is a step-level concern, controlled by the `--tenant` flag on the normal commands.

## Domains

See [Domains › Multi-tenant domains](/guide/domains#multi-tenant-domains) for the full domain rules.
