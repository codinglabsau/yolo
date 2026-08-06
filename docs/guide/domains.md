# Domains

A YOLO app can be served on any domain or subdomain you own. The domain's hosted zone must exist in **Route 53** on the same AWS account — YOLO manages the records and the TLS certificate, but the zone itself is the one prerequisite it expects to find.

For a single (non-tenanted) app, set the domain at the environment level:

```yaml
environments:
  production:
    domain: example.com
```

YOLO provisions an ACM certificate, attaches it to the load balancer's HTTPS listener via SNI, and points DNS at the ALB. HTTP traffic on `:80` is redirected to HTTPS on `:443`.

## Apex and `www`

`domain` is the **canonical host** — the single host your app is served on. When it's one half of the apex/`www` pair, YOLO serves the canonical host and **301-redirects the other half** to it (preserving path and query). The redirect is issued by the load balancer, before the request reaches a container.

You choose the canonical host simply by which one you set as `domain`:

```yaml
domain: example.com       # serves the apex; www.example.com → 301 → example.com
```

```yaml
domain: www.example.com   # serves www; example.com → 301 → www.example.com
```

Both halves resolve to the load balancer (so the redirect can catch the non-canonical one), and the certificate already covers both (the apex + the `*.apex` wildcard). You don't configure `www` separately — there's nothing to toggle.

There is no `apex` key — YOLO **derives** the apex (the registrable root, naming the Route 53 hosted zone to write into) from `domain`. It walks the domain's label-suffixes longest-first and uses the longest one that already has a hosted zone in the account; when none exists yet, the domain itself is the apex (with any leading `www.` stripped, so the apex is never the `www` host).

## Serving from a subdomain

To serve the app from a subdomain, just set `domain` to the subdomain:

```yaml
domain: app.example.com
```

YOLO finds the `example.com` hosted zone by walking up the labels from `app.example.com`, and writes the `app.example.com` record into it — no `apex` key to set. A bare subdomain like this is served on its own — it's not one half of the apex/`www` pair, so no redirect is set up.

## Serving every subdomain

To serve the app from `domain` **and** every subdomain beneath it — the usual shape for a multi-tenant app that gives each tenant a subdomain — add `wildcard-subdomains`:

```yaml
domain: app.example.com
wildcard-subdomains: true
```

`app.example.com` is served as the canonical host, and `acme.app.example.com` (or any other single label) reaches the same service, which resolves the tenant from the request host. YOLO adds `*.app.example.com` to the app's listener rule and writes one `*.app.example.com` alias record, so a new subdomain needs no infrastructure change.

This also changes where the certificate is issued. Normally YOLO requests one for the apex (`example.com` + `*.example.com`), but a wildcard matches a single label, so `*.example.com` covers `app.example.com` and not `acme.app.example.com`. With `wildcard-subdomains` the certificate is issued for the domain instead (`app.example.com` + `*.app.example.com`). Both the DNS validation record and the wildcard alias go into the existing `example.com` zone — no second hosted zone, no NS delegation.

The wildcard is deliberately scoped to the app's own `domain` rather than the apex. Several apps often share one zone, and a wildcard at the apex would let whichever app won the load balancer's rule ordering swallow its siblings' traffic.

Two limits worth knowing: wildcards are **one label deep** on both the certificate and the listener rule (`a.b.app.example.com` is not served), and under [`multitenancy`](/guide/multi-tenancy) the flag moves inside the block, onto the landlord or tenant whose domain it wildcards.

### With the apex/`www` redirect

When the canonical host is the apex, the wildcard (`*.example.com`) also matches the `www` sibling that the [redirect rule](#apex-and-www) answers on — so both rules match that host and only their priority decides which wins. YOLO bands rule priorities to make that deterministic: **a redirect rule always outranks every forward rule**, so `www` keeps 301-ing to the apex rather than being served by the wildcard. The certificate covers it either way (`example.com` + `*.example.com`).

On a bare subdomain there's no apex/`www` pair at all, so no redirect rule exists and nothing overlaps. That's the usual multi-tenant shape.

A **`www`-canonical** domain (`domain: www.example.com`) is refused with `wildcard-subdomains`: the wildcard would land at `*.www.example.com`, which serves nobody, and moving the certificate onto the `www` host would leave the apex it redirects *from* uncovered — a TLS failure before the 301 could fire. Serve from the apex or a bare subdomain instead.

## One app across two environments

Every other resource YOLO creates is env-scoped (`yolo-{env}-{app}-…`), so two environments of the same app — say a `staging` trial on `app-staging.example.com` alongside `production` on `example.com` — never collide. The one exception is the **hosted zone**: a real domain has a single zone, so both environments write into it.

That's safe by design:

- **Records stay isolated.** Each environment UPSERTs only its own `domain` (and, for an apex/`www` canonical host, that pair). A trial on a bare subdomain has no `www` sibling, so it only ever writes its own record — it never touches the production apex.
- **Ownership is first-writer-wins.** The zone carries a `yolo:environment` tag for [`audit`](/guide/provisioning#auditing-what-s-deployed). Whichever environment provisions it first owns that tag; later environments **never overwrite it** (which would otherwise flap on every sync and read as drift, refusing both environments' deploys at the [pre-deploy in-sync check](/guide/ci-cd#yolo-deploy-refuses-to-run-against-drift)). `sync:app` instead surfaces a one-line **warning** naming the owning environment, so the shared zone is visible without being a gate.

## Headless apps

An app with no public web front — a background worker, a queue consumer, an internal job runner — runs **headless**: omit `domain` (and any tenant domains) *and* the web tier. A headless app is a [web-less worker app](/reference/manifest#where-each-role-runs) — a standalone `tasks.queue` and/or `tasks.scheduler` with no `tasks.web`:

```yaml
environments:
  production:
    # no domain → nothing exposed; no web task → a scheduler-only worker app
    tasks:
      web: false
      queue: false
      scheduler: true
```

With no domain there is no hosted zone, certificate, ALB attachment, or DNS — and nothing that needs them. The worker still deploys, consumes its queue and fires its schedule; it just has no URL.

A **web task always requires a domain**: the task security group only accepts traffic from the load balancer, and without a domain no listener rule ever routes to the service — a web server nobody can reach, burning a Fargate task. A `tasks.web` block with no `domain` (or, multi-tenant, no tenant domains) is refused at validation. A worker app may still *declare* a `domain` — it's metadata, and YOLO keeps the hosted zone and certificate provisioned (unattached) so the web tier can return later.

Need an image that builds but runs no container at all? Omit the `tasks` block entirely — see [App modes](/reference/manifest#app-modes).

## Multi-tenant domains

Multi-tenant apps configure domains per tenant rather than at the environment level — see [Multi-Tenancy](/guide/multi-tenancy). A multi-tenant app must **not** set `domain` at the environment level; each tenant carries its own (and its apex is derived from it the same way).
