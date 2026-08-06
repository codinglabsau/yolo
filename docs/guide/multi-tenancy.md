# Multi-Tenancy

Everything multi-tenant lives in one `multitenancy` block. One container image and one ECS service serve every tenant; what differs per tenant is how it's routed to, and therefore what it costs to onboard one.

| | [Under the landlord's wildcard](#tenants-under-the-landlord-s-domain) | [On its own domain](#tenants-on-their-own-domains) |
| --- | --- | --- |
| Tenant is reached at | `{tenant}.{landlord domain}` | any domain |
| Declared with | a bare tenant id | that tenant's `domain` |
| Per-tenant AWS resources | none | hosted zone, certificate, SNI attachment, listener rules, DNS records |
| Onboarding a tenant | a row in your database | a manifest edit and a `yolo sync` |

**They compose.** The two are per-tenant choices, not app-wide modes: a tenant with no `domain` of its own is served under the landlord's wildcard, and one with a `domain` gets the full set. Mixing them in one app is the normal migration path — a tenant graduates to its own domain by gaining one line.

## The party shape

The landlord and each tenant are declared the same way, so one rule covers both:

```yaml
domain: …                  # the host this party is served on
wildcard-subdomains: true  # …and every subdomain of it, one label deep
```

`apex` is never declared — YOLO derives it from `domain` by walking the domain's labels against the hosted zones in the account, so a certificate lands on the right zone with nothing to configure.

## Tenants under the landlord's domain

Wildcard the landlord and every tenant is reached beneath it, with no infrastructure of its own:

```yaml
environments:
  production:
    account-id: '123456789012'
    region: ap-southeast-2
    tasks:
      web:
        autoscaling: true
    multitenancy:
      landlord:
        domain: app.example.com
        wildcard-subdomains: true
      queue-isolation: dedicated
      tenants:
        acme:
        globex:
```

`app.example.com` serves the landlord; `acme.app.example.com` and every other subdomain reach the same service, which resolves the tenant from the request host. YOLO provisions **one** certificate covering `app.example.com` + `*.app.example.com`, one wildcard listener rule, and one `*.app.example.com` alias record — so bringing a tenant live needs no infrastructure run.

Declaring the tenants anyway is what gets them their own AWS resources: with `queue-isolation: dedicated` each gets its own SQS queue, depth alarm and worker program. Drop the `tenants` block entirely and the app still works as a wildcard-served app that resolves tenants from its own database — YOLO just knows nothing about them.

The wildcard is scoped to the landlord's own `domain`, never the apex: several apps commonly share one zone (`app.example.com`, `admin.example.com`), and a wildcard at the apex would have one app swallow the others' traffic. It is one label deep, so `acme.app.example.com` is served and `a.b.app.example.com` is not.

## Tenants on their own domains

Give a tenant a `domain` and it gets the full set:

```yaml
    multitenancy:
      landlord:
        domain: admin.example.com
      queue-isolation: dedicated
      tenants:
        acme:
          domain: acme.com.au
        globex:
          domain: globex.io
          wildcard-subdomains: true
```

Each such tenant gets its own hosted zone, its own DNS-validated certificate, an SNI attachment onto the shared `:443` listener, a forward rule routing its host to the app's target group, and — when its domain is one half of the apex/`www` pair — a redirect rule 301ing the sibling. `globex` additionally serves `*.globex.io`, its certificate moving off the apex onto `globex.io` so the wildcard reaches a level deeper.

Rule identity is the rule's `Name` tag, keyed by tenant id, so changing one tenant's domain rewrites that tenant's rule in place and never touches a sibling's.

### Absorbing a domain that already exists

A tenant domain usually pre-dates YOLO — the zone, and often a certificate, are already live. Sync **adopts** rather than recreates:

- A hosted zone found for the tenant's apex is tag-stamped, not created. It is never deleted, and `destroy:app` withdraws only the records YOLO itself wrote.
- A certificate found for the tenant's certificate domain is reused. YOLO never requests a duplicate and never deletes one — teardown detaches it from the listener and leaves it standing.

Because every step diffs before it writes, a sync over already-correct infrastructure reports **Already in sync** instead of proposing work.

## Mixing the two

The two shapes are per-tenant, so one app can run both — which is how a tenant migrates onto its own domain:

```yaml
    multitenancy:
      landlord:
        domain: app.example.com
        wildcard-subdomains: true
      tenants:
        acme:
          domain: acme.com.au   # graduated: zone, certificate, rules of its own
        globex:                 # still globex.app.example.com, under the wildcard
```

Each per-tenant DNS/TLS step asks one question — *does the app's own certificate already cover this host?* — and skips itself when the answer is yes. Nothing else in the plan changes.

## Landlord migrations

Answer "yes" to the multi-tenant prompt in `yolo init` and it scaffolds the block along with landlord/tenant migration hooks:

```yaml
deploy:
  - php artisan migrate --path=database/migrations/landlord --force
  - php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"
```

## What gets provisioned

`yolo sync` (or `sync:app`) fans the per-tenant steps out across every tenant:

- **Queues**, when [`queue-isolation`](/reference/manifest#multitenancy-queue-isolation) is `dedicated` — a **landlord** SQS queue and depth alarm for shared/central work, plus a per-tenant queue and alarm for each tenant. On the default `shared` strategy one queue set serves every tenant instead, with the tenant carried in the job payload.
- **Hosted zone, certificate, SNI attachment and listener rules**, for each tenant on a domain the landlord's certificate doesn't already cover.
- **DNS records** for every tenant domain, pointed at the shared load balancer, UPSERTed during `yolo deploy`.

### Declaring no tenants at all

`multitenancy.tenants` is optional. A block with just a landlord is the shape of an app that resolves every tenant from its own database:

```yaml
    multitenancy:
      landlord:
        domain: app.example.com
        wildcard-subdomains: true
```

That provisions exactly what the solo shape does — one certificate covering `app.example.com` + `*.app.example.com`, one wildcard listener rule, one alias record, one queue set — because there is nothing to fan out over. Tenants come and go as database rows, with no infrastructure run and nothing in the manifest to keep in step.

## Tearing down

`destroy:app` reverses all of it. Each tenant's listener rules, SNI attachment, queues and DNS records are removed alongside the app's own, each step self-gating exactly as its sync counterpart did — so a tenant under the landlord's wildcard, which never had resources of its own, reports nothing.

Two things deliberately survive, both per tenant: the **hosted zone** and the **ACM certificate**. They are the tenant's domain-level infrastructure, not YOLO's — teardown withdraws only the records YOLO wrote and detaches the certificate from the listener. That is the same asymmetry as [absorbing a domain](#absorbing-a-domain-that-already-exists), read backwards: YOLO adopts a domain without taking it over, and releases it without taking it down.

## Single-tenant operations

Use `--tenant=<id>` to narrow the per-tenant steps to one tenant — useful when onboarding a new tenant or running a single-tenant cutover without touching the rest:

```bash
yolo sync:app production --tenant=acme
```

There is no `sync:tenant` or `deploy:tenant` verb — tenancy is a step-level concern, controlled by the `--tenant` flag on the normal commands.

## Domains

See [Domains › Multi-tenant domains](/guide/domains#multi-tenant-domains) for the full domain rules.
