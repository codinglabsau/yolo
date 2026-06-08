# Domains

A YOLO app can be served on any domain or subdomain you own. The domain's hosted zone must exist in **Route 53** on the same AWS account — YOLO manages the records and the TLS certificate, but the zone itself is the one prerequisite it expects to find.

For a single (non-tenanted) app, set the domain at the environment level:

```yaml
environments:
  production:
    domain: codinglabs.com.au
```

YOLO provisions an ACM certificate, attaches it to the load balancer's HTTPS listener via SNI, and points DNS at the ALB. HTTP traffic on `:80` is redirected to HTTPS on `:443`.

## Apex and `www`

`domain` is the **canonical host** — the single host your app is served on. When it's one half of the apex/`www` pair, YOLO serves the canonical host and **301-redirects the other half** to it (preserving path and query). The redirect is issued by the load balancer, before the request reaches a container.

You choose the canonical host simply by which one you set as `domain`:

```yaml
domain: codinglabs.com.au       # serves the apex; www.codinglabs.com.au → 301 → codinglabs.com.au
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
apex: codinglabs.com.au
domain: app.codinglabs.com.au
```

`apex` tells YOLO which Route 53 hosted zone to write into; `domain` is where the app is served. If you omit `apex`, it defaults to `domain`. A bare subdomain like this is served on its own — it's not one half of the apex/`www` pair, so no redirect is set up.

## Headless apps

An app that has no public web front — a worker-only service, an internal API behind something else, a queue consumer — can run **headless**. Omit `domain`, `apex`, and tenant domains entirely:

```yaml
environments:
  production:
    account-id: '123456789012'
    region: ap-southeast-2
    # no domain / apex / tenants
    tasks:
      web:
        queue: true
```

With nothing to route, YOLO skips the hosted zone, certificate, ALB attachment, and DNS for that app. It's still deployed and can still process queues and scheduled work.

## Multi-tenant domains

Multi-tenant apps configure domains per tenant rather than at the environment level — see [Multi-Tenancy](/guide/multi-tenancy). A multi-tenant app must **not** set `domain`/`apex` at the environment level; each tenant carries its own.
