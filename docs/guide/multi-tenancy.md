# Multi-Tenancy

Everything multi-tenant lives in one `multitenancy` block. One container image and one ECS service serve every tenant. What differs is **how a request identifies its tenant** — and that decides what YOLO provisions and what onboarding a tenant costs.

| | [In the route](#tenant-in-the-route) | [In the subdomain](#tenant-in-the-subdomain) | [Its own domain](#tenant-on-its-own-domain) |
| --- | --- | --- | --- |
| Tenant is reached at | `app.example.com/acme` | `acme.app.example.com` | `acme.com.au` |
| Needs `wildcard-subdomains` | no | yes | no |
| Declared in the manifest | not at all | not at all | that tenant's `domain` |
| Per-tenant AWS resources | none | none | hosted zone, certificate, SNI attachment, listener rules, DNS records |
| Onboarding a tenant | a row in your database | a row in your database | a manifest edit and a `yolo sync` |

The first two cost YOLO nothing per tenant — the app resolves the tenant from the request itself, so tenants come and go as database rows with no infrastructure run. Only a custom domain needs YOLO to know a tenant exists.

**The third composes with either of the first two**, which is how a tenant migrates onto its own domain: it is a per-tenant choice, not an app-wide mode.

## How a landlord and a tenant are declared

The landlord and each tenant are declared the same way, so one rule covers both:

```yaml
domain: …                  # the host it is served on
wildcard-subdomains: true  # …and every subdomain of it, one label deep
```

`apex` is never declared — YOLO derives it from `domain` by walking the domain's labels against the hosted zones in the account, so a certificate lands on the right zone with nothing to configure.

## Tenant in the route

The app serves one host and reads the tenant off the URL — `app.example.com/acme`, or a header, or the session. Nothing about that is visible to AWS, so the manifest is a landlord and nothing else:

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
```

No `wildcard-subdomains`, no `tenants`. YOLO provisions exactly what a single-tenant app gets — one hosted zone, one certificate, one forward rule, one queue set — because there is nothing to fan out over. Tenants are database rows; onboarding one touches neither the manifest nor AWS.

This is the shape most `tenant_id`-column apps want, and the cheapest place to start.

## Tenant in the subdomain

Wildcard the landlord and every tenant is reached beneath it:

```yaml
    multitenancy:
      landlord:
        domain: app.example.com
        wildcard-subdomains: true
```

`app.example.com` serves the landlord; `acme.app.example.com` and every other subdomain reach the same service, which resolves the tenant from the request host. YOLO provisions **one** certificate covering `app.example.com` + `*.app.example.com`, one wildcard listener rule, and one `*.app.example.com` alias record — so, as with the route shape, bringing a tenant live needs no infrastructure run and no manifest edit.

The wildcard is scoped to the landlord's own `domain`, never the apex: several apps commonly share one zone (`app.example.com`, `admin.example.com`), and a wildcard at the apex would have one app swallow the others' traffic. It is one label deep, so `acme.app.example.com` is served and `a.b.app.example.com` is not.

## Tenant on its own domain

A tenant reached at a domain of its own is the one case YOLO has to know about, because only then does it have resources to provision. Declare the tenant and give it a `domain`:

```yaml
    multitenancy:
      landlord:
        domain: app.example.com
        wildcard-subdomains: true
      tenants:
        acme:
          domain: acme.com.au        # its own zone, certificate and rules
        globex:
          domain: globex.io
          wildcard-subdomains: true  # …and *.globex.io as well
```

Each such tenant gets its own hosted zone, its own DNS-validated certificate, an SNI attachment onto the shared `:443` listener, a forward rule routing its host to the app's target group, and — when its domain is one half of the apex/`www` pair — a redirect rule 301ing the sibling. `globex` additionally serves `*.globex.io`, its certificate moving off the apex onto `globex.io` so the wildcard reaches a level deeper.

Rule identity is the rule's `Name` tag, keyed by tenant id, so changing one tenant's domain rewrites that tenant's rule in place and never touches a sibling's.

A tenant declared without a `domain` is served the way the landlord is (under its wildcard, or off the route) and gets no DNS or TLS resources — [declaring it anyway](#declaring-tenants-without-a-domain) buys it queues of its own and nothing else.

### Absorbing a domain that already exists

A tenant domain usually pre-dates YOLO — the zone, and often a certificate, are already live. Sync **adopts** rather than recreates:

- A hosted zone found for the tenant's apex is tag-stamped, not created. It is never deleted, and `destroy:app` withdraws only the records YOLO itself wrote.
- A certificate found for the tenant's certificate domain is reused. YOLO never requests a duplicate and never deletes one — teardown detaches it from the listener and leaves it standing.

Because every step diffs before it writes, a sync over already-correct infrastructure reports **Already in sync** instead of proposing work.

## Graduating a tenant onto its own domain

A custom domain is a per-tenant choice, so one app runs it alongside either of the other two shapes — which is how a tenant migrates:

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

## Migrations

Answer "yes" to the multi-tenant prompt in `yolo init` and it scaffolds the `deploy` hooks to match your migration layout, since what a tenanted app has to migrate depends on where its tenants live.

A **single database** scoping rows by a `tenant_id` column has one flat `database/migrations` and one call, exactly like a solo app:

```yaml
deploy:
  - php artisan migrate --force
```

A **database per tenant** splits its migrations, and both sets have to run — the second over every tenant connection. `init` scaffolds this form when it finds `database/migrations/landlord` **and** `database/migrations/tenant`:

```yaml
deploy:
  - php artisan migrate --path=database/migrations/landlord --force
  - php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"
```

`init` says which it assumed. Neither is enforced afterwards — `deploy` is yours to edit, and an app that adopts a split layout later just changes the hooks.

## What gets provisioned

`yolo sync` (or `sync:app`) fans the per-tenant steps out across every tenant:

- **Queues**, when [`queue-isolation`](/reference/manifest#multitenancy-queue-isolation) is `dedicated` — a **landlord** SQS queue and depth alarm for shared/central work, plus a per-tenant queue and alarm for each tenant. On the default `shared` strategy one queue set serves every tenant instead, with the tenant carried in the job payload.
- **Hosted zone, certificate, SNI attachment and listener rules**, for each tenant on a domain the landlord's certificate doesn't already cover.
- **DNS records** for every tenant domain, pointed at the shared load balancer, UPSERTed during `yolo deploy`.

### Declaring tenants without a domain

`multitenancy.tenants` is optional, and the [route](#tenant-in-the-route) and [subdomain](#tenant-in-the-subdomain) shapes leave it out entirely — YOLO has nothing to provision per tenant, so a list it can only fall out of step with buys nothing.

The one reason to declare a tenant anyway is [`queue-isolation`](/reference/manifest#multitenancy-queue-isolation): on `dedicated`, each declared tenant gets its own SQS queue, depth alarm and worker program. Everything else about it stays exactly as it was.

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
