# Domains

Applications hosted with YOLO can be served on any domain or subdomain that you own. The domain must be added to Route53 in advance.

## Standalone Apps

Set the domain directly in your manifest:

```yaml
domain: codinglabs.com.au
```

The app will be served on `codinglabs.com.au`, with `www.codinglabs.com.au` redirecting to the apex.

### Subdomain Primary

If the app should be served on a subdomain (including `www.`), specify the apex separately:

```yaml
apex: codinglabs.com.au
domain: www.codinglabs.com.au
```

The app will be served on `www.codinglabs.com.au`, with `codinglabs.com.au` redirecting to `www`.

## Multi-Tenant Apps

Domains are configured per tenant:

```yaml
tenants:
  boating:
    domain: boating.outdoors-with-yolo.com
    apex: outdoors-with-yolo.com

  camping:
    domain: camping.outdoors-with-yolo.com
    apex: outdoors-with-yolo.com

  fishing:
    domain: fishing-with-yolo.com
```
