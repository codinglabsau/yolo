# Multi-Tenancy

YOLO supports two shapes of multi-tenancy. One container image and one ECS service serve every tenant either way — what differs is how a tenant is routed to, and therefore what it costs to onboard one.

| | [Wildcard subdomains](#wildcard-subdomains) | [Tenant domains](#tenant-domains) |
| --- | --- | --- |
| Tenant is reached at | `{tenant}.{domain}` | any domain, one per tenant |
| Declared with | `wildcard-subdomains: true` | a `tenants` block |
| Per-tenant AWS resources | none | DNS records, optionally queues |
| Onboarding a tenant | a row in your database | a manifest edit and a `yolo sync` |

They are mutually exclusive. Reach for the wildcard unless a tenant needs to be served on a domain of their own.

## Wildcard subdomains

Set [`wildcard-subdomains`](/reference/manifest#wildcard-subdomains) and every subdomain of the app's `domain` is served by the app:

```yaml
environments:
  production:
    account-id: '123456789012'
    region: ap-southeast-2
    domain: app.example.com
    wildcard-subdomains: true
    tasks:
      web:
        autoscaling: true
```

`app.example.com` serves the landlord; `acme.app.example.com` and every other subdomain reach the same service, which resolves the tenant from the request host. YOLO provisions one certificate covering `app.example.com` + `*.app.example.com`, one wildcard listener rule, and one `*.app.example.com` alias record — so a new tenant needs no infrastructure change at all.

The wildcard is scoped to the app's own `domain`, never the apex: several apps commonly share one zone (`app.example.com`, `admin.example.com`), and a wildcard at the apex would have one app swallow the others' traffic.

Queues are not fanned out in this mode — one queue set serves every tenant, with the tenant carried in the job payload. See [`queue-isolation`](/reference/manifest#queue-isolation) if a tenant needs its own.

## Tenant domains

Where each tenant is served on a domain of their own and gets its own isolated queue. Declare tenants under the environment, keyed by a unique tenant id:

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

- Queues, when [`queue-isolation`](/reference/manifest#queue-isolation) is `dedicated` — a **landlord** SQS queue and depth alarm for shared/central work, plus a **per-tenant** queue and alarm for each tenant. On the default `shared` strategy one queue set serves every tenant instead.
- Per-tenant DNS records, pointed at the shared load balancer, are UPSERTed during `yolo deploy`.

::: warning Tenant HTTPS is not built yet
Nothing provisions a per-tenant hosted zone or certificate, and the HTTPS listener rule is only created for an environment-level `domain`. So a `tenants` app gets its queues and DNS records but nothing that terminates TLS or routes its hosts to the service. Use [wildcard subdomains](#wildcard-subdomains) — that shape is complete.
:::

## Single-tenant operations

Use `--tenant=<id>` to narrow the per-tenant steps to one tenant — useful when onboarding a new tenant or running a single-tenant cutover without touching the rest:

```bash
yolo sync:app production --tenant=acme
```

There is no `sync:tenant` or `deploy:tenant` verb — tenancy is a step-level concern, controlled by the `--tenant` flag on the normal commands.

## Domains

See [Domains › Multi-tenant domains](/guide/domains#multi-tenant-domains) for the full domain rules.
