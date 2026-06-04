# The Container Image

YOLO deploys your app as a single Docker image to Fargate. That one image runs everything — the web server and, optionally, queue workers and the scheduler — supervised by [supervisord](https://supervisord.org/).

You own a small `Dockerfile`; YOLO generates the moving parts (entrypoint, process config) into the build context at build time. This page is the **contract** between the two.

## What `yolo init` scaffolds

`yolo init` writes a `Dockerfile` and `.dockerignore` to your project root. The default Dockerfile is built on [FrankenPHP](https://frankenphp.dev/) and looks like this:

```dockerfile
FROM dunglas/frankenphp:1-php8.4-alpine

# nodejs is the runtime for Inertia SSR (tasks.web.ssr); drop it if you don't use SSR.
RUN apk add --no-cache git supervisor nodejs \
    && install-php-extensions intl pcntl bcmath redis pdo_mysql opcache excimer

WORKDIR /app

COPY --chown=www-data:www-data . /app
# Place the generated supervisor config at a default search path so both
# `supervisord` and an interactive `supervisorctl` find it without -c.
COPY docker/supervisord.conf /etc/supervisord.conf
RUN chmod +x /app/.yolo-entrypoint.sh

USER www-data

ENV SERVER_NAME=:8000
EXPOSE 8000

# The entrypoint dispatches on the role argument (default web → supervisord).
# Each ECS task definition passes its own role; a one-off command (e.g. a
# deploy migration) is exec'd directly.
ENTRYPOINT ["/app/.yolo-entrypoint.sh"]
CMD ["web"]
```

Customise it freely — add PHP extensions, system packages, a different base image. Just keep the contract below intact.

## What YOLO generates

During `yolo build`, YOLO writes two files into the build context that your Dockerfile copies in:

| File | Purpose |
|---|---|
| `.yolo-entrypoint.sh` | The container entrypoint. Runs your `deploy-all` hooks (e.g. `php artisan optimize`) on startup, then dispatches on the container command: a role (`web` / `queue` / `scheduler`) is supervised and traps `SIGTERM` so the web tier keeps serving across the ALB drain window before forwarding the stop; any other command — a one-off task such as a deploy migration — is `exec`'d directly (no supervise, no drain). ECS can override the command, not the entrypoint, which is why the dispatch lives here. |
| `docker/supervisord.conf` | The web container's supervisord program tree — FrankenPHP/Octane, plus the `queue:work` worker and the scheduler unless you've extracted them into their own services. A crontab is generated alongside it (the scheduler always runs somewhere). A standalone queue that also hosts the scheduler gets a second `docker/supervisord.queue.conf`. |

Because these are generated, your Dockerfile doesn't need to know how to run Octane, the queue, or the scheduler — it just copies the config and runs the entrypoint.

## The contract

For your image to work with YOLO, the Dockerfile must:

1. **Copy the application** into `/app`:
   ```dockerfile
   WORKDIR /app
   COPY --chown=www-data:www-data . /app
   ```
2. **Copy the generated supervisord config** to a default search path:
   ```dockerfile
   COPY docker/supervisord.conf /etc/supervisord.conf
   ```
3. **Make the generated entrypoint executable and use it:**
   ```dockerfile
   RUN chmod +x /app/.yolo-entrypoint.sh
   ENTRYPOINT ["/app/.yolo-entrypoint.sh"]
   CMD ["web"]
   ```
4. **Expose the web port**, and make sure it matches `tasks.web.port` in your manifest (default `8000`). The ALB health-checks this port at `/up` (Laravel's built-in [health route](https://laravel.com/docs/deployment#the-health-route)) — override the path or timing via [`tasks.web.health-check.*`](/reference/manifest#tasks-web-health-check).
5. Have **`supervisor`** installed (the default Dockerfile installs it via `apk add`).

## Processes in the container

Every app runs three roles — web, the queue worker, and the scheduler — and by default they all share the one web container:

```yaml
tasks:
  web:
    port: 8000
```

- **Web** always runs — `php artisan octane:start` serving Laravel Octane. `octane:start` boots whichever server your app's `OCTANE_SERVER` names; `yolo init` seeds `OCTANE_SERVER=frankenphp` in your `.env` to match the scaffolded Dockerfile. The server is an app concern, not infrastructure YOLO injects — to run a different Octane server, change the base image **and** `OCTANE_SERVER` together.
- **The queue worker** runs `queue:work`, bundled in the web container until you extract it.
- **The scheduler** runs a busybox `crond` that fires `php artisan schedule:run` every minute, bundled until you extract it. (YOLO uses cron, not `schedule:work`, so the scheduler survives `SIGTERM` cleanly.)
- **`ssr: true`** adds Inertia's SSR renderer — see [Inertia SSR](#inertia-ssr) below.

::: tip Independent task groups
Extract the queue and/or scheduler into their own ECS service — so you can scale the web tier without duplicating the scheduler — by adding a top-level `tasks.queue` / `tasks.scheduler` block. Where each role then runs is derived from which blocks you've added; see [Where each role runs](/reference/manifest#where-each-role-runs) and [Scaling](/guide/scaling).
:::

## Inertia SSR

Set [`tasks.web.ssr: true`](/reference/manifest#tasks-web) to server-render your Inertia + Vue pages (better SEO, faster first paint). YOLO adds an `ssr` program to supervisord that runs `php artisan inertia:start-ssr` — a Node process listening on `127.0.0.1:13714`. PHP calls it on localhost for each render, so **SSR is always bundled in the web container** — never its own service. YOLO injects `INERTIA_SSR_ENABLED=true` (unless your `.env` already sets it); the render URL comes from Inertia's default `config/inertia.php`.

Two things are on you:

1. **A Node runtime in your image.** The scaffolded Dockerfile already installs `nodejs`, so SSR works out of the box. If you've slimmed it out (or moved to a base image without Node), add it back — `yolo build` checks the Dockerfile for a Node runtime when `ssr` is on and asks for confirmation if it can't find one (a warning only — it never blocks a non-interactive CI build).
2. **An SSR bundle from your build.** Your `npm run build` must emit the SSR bundle (`bootstrap/ssr/`) — that's standard [Inertia SSR](https://inertiajs.com/server-side-rendering) setup in your `vite.config.js`. The bundle is copied into the image automatically (it isn't excluded by `.dockerignore` or the build's `node_modules` cleanup).

If the SSR process is down, Inertia falls back to client-side rendering, so the app keeps serving — the ALB health check stays on PHP's `/up` and isn't coupled to SSR. supervisord restarts a crashed renderer automatically.

## The `.dockerignore`

The scaffolded `.dockerignore` trims the build context but **deliberately keeps** a few things the image depends on:

- `.env` — the environment's file, baked in at build time
- `vendor` — installed by your `build` hook, not the Dockerfile
- `public/build` — compiled Vite assets
- `docker/` — the generated `supervisord.conf`
- `.yolo-entrypoint.sh` — the generated entrypoint

Don't add those to `.dockerignore` or the build will produce a broken image.

## Graceful shutdown

When ECS replaces a task it sends `SIGTERM`. The entrypoint traps it and holds the web tier open for the **shutdown grace period** so the ALB can drain in-flight requests before the container exits — that's what gives you deploys with no 502s. Tune it per process:

```yaml
tasks:
  web:
    shutdown-grace-period: 30   # seconds; bump for long uploads/exports/SSE
```

The same value sets the container's `stopTimeout` and the ALB deregistration delay, keeping all three in lock-step. See [`tasks.web.shutdown-grace-period`](/reference/manifest#tasks-web).
