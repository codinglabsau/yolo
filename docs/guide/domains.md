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
apex: example.com
domain: www.example.com         # serves www; example.com → 301 → www.example.com
```

Both halves resolve to the load balancer (so the redirect can catch the non-canonical one), and the certificate already covers both (`apex` + the `*.apex` wildcard). You don't configure `www` separately — there's nothing to toggle.

The apex record cannot itself start with `www.` — YOLO rejects that as a manifest integrity error.

## Serving from a subdomain

To serve the app from a subdomain, set `domain` to the subdomain and `apex` to the registrable root:

```yaml
apex: example.com
domain: app.example.com
```

`apex` tells YOLO which Route 53 hosted zone to write into; `domain` is where the app is served. If you omit `apex`, it defaults to `domain`. A bare subdomain like this is served on its own — it's not one half of the apex/`www` pair, so no redirect is set up.

## One app across two environments

Every other resource YOLO creates is env-scoped (`yolo-{env}-{app}-…`), so two environments of the same app — say a `staging` trial on `app-staging.example.com` alongside `production` on `example.com` — never collide. The one exception is the **hosted zone**: a real domain has a single zone, so both environments write into it.

That's safe by design:

- **Records stay isolated.** Each environment UPSERTs only its own `domain` (and, for an apex/`www` canonical host, that pair). A trial on a bare subdomain has no `www` sibling, so it only ever writes its own record — it never touches the production apex.
- **Ownership is first-writer-wins.** The zone carries a `yolo:environment` tag for [`audit`](/guide/provisioning#auditing-what-s-deployed). Whichever environment provisions it first owns that tag; later environments **never overwrite it** (which would otherwise flap on every sync and read as drift, refusing both environments' deploys at the [pre-deploy in-sync check](/guide/ci-cd#yolo-deploy-refuses-to-run-against-drift)). `sync:app` instead surfaces a one-line **warning** naming the owning environment, so the shared zone is visible without being a gate.

## Headless apps

An app with no public web front — a background worker, a queue consumer, an internal job runner — can run **headless**: omit `domain`, `apex`, and any tenant domains.

```yaml
environments:
  production:
    # no domain / apex / tenants → headless
    tasks:
      web:
        autoscaling: true
```

It still declares `tasks.web`. That's the container the app runs — the queue worker and scheduler ride inside it by default ([where each role runs](/reference/manifest#where-each-role-runs)) — so "headless" isn't about dropping the web tier, it's about not exposing it. With no domain to route, YOLO skips the hosted zone, certificate, ALB attachment, and DNS; the container still deploys and still processes queued and scheduled work, it just has no public URL.

Need an image that builds but runs no container at all? Omit the `tasks` block entirely — see [App modes](/reference/manifest#app-modes).

## Multi-tenant domains

Multi-tenant apps configure domains per tenant rather than at the environment level — see [Multi-Tenancy](/guide/multi-tenancy). A multi-tenant app must **not** set `domain`/`apex` at the environment level; each tenant carries its own.
