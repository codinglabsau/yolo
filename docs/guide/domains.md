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

An app with no public web front — a background worker, a queue consumer, an internal job runner — can run **headless**: omit `domain`, `apex`, and any tenant domains.

```yaml
environments:
  production:
    # no domain / apex / tenants → headless
    tasks:
      web: true
```

It still declares `tasks.web`. That's the container the app runs — the queue worker and scheduler ride inside it by default ([where each role runs](/reference/manifest#where-each-role-runs)) — so "headless" isn't about dropping the web tier, it's about not exposing it. With no domain to route, YOLO skips the hosted zone, certificate, ALB attachment, and DNS; the container still deploys and still processes queued and scheduled work, it just has no public URL.

Need an image that builds but runs no container at all? Omit the `tasks` block entirely — see [App modes](/reference/manifest#app-modes).

## Multi-tenant domains

Multi-tenant apps configure domains per tenant rather than at the environment level — see [Multi-Tenancy](/guide/multi-tenancy). A multi-tenant app must **not** set `domain`/`apex` at the environment level; each tenant carries its own.
