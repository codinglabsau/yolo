# The Container Image

YOLO deploys your app as a single Docker image to Fargate. That one image runs everything — the web server and, optionally, queue workers and the scheduler — supervised by [supervisord](https://supervisord.org/).

You own a small `Dockerfile`; YOLO generates the moving parts (entrypoint, process config) into the build context at build time. This page is the **contract** between the two.

## What `yolo init` scaffolds

`yolo init` writes a `Dockerfile` and `.dockerignore` to your project root. The default Dockerfile is built on [FrankenPHP](https://frankenphp.dev/) and looks like this:

```dockerfile
FROM dunglas/frankenphp:1-php8.4-alpine

# supercronic runs the scheduler's cron as a non-root user (busybox crond can't);
# nodejs is the runtime for Inertia SSR (tasks.web.ssr) — drop it if you don't use SSR.
# redis and excimer install from pinned GitHub tags rather than their PECL aliases —
# pecl.php.net (the aliases' source) times out routinely. ADD clones the phpredis
# tag with submodules (the tag tarball is missing liblzf), and the installer
# accepts the clone as a source path.
ADD https://github.com/phpredis/phpredis.git#6.3.0 /usr/src/phpredis
RUN apk add --no-cache git supervisor supercronic nodejs \
    && install-php-extensions intl pcntl bcmath /usr/src/phpredis pdo_mysql opcache wikimedia/php-excimer@1.2.6 \
    && rm -rf /usr/src/phpredis

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
5. Have **`supervisor`** and **`supercronic`** installed (the default Dockerfile installs both via `apk add`). supervisord runs the container's process tree; [supercronic](https://github.com/aptible/supercronic) drives the scheduler's cron — the container runs as `www-data`, and busybox `crond` silently loads zero jobs for a non-root user, so it cannot stand in.

## Runtime checks

`yolo build` runs three preflights so a deploy can't ship an image that won't run:

- **Octane** — *before* the build, it reads `composer.lock` and fails if `laravel/octane` isn't in the production requirements, since the web role runs `octane:start`. Skipped when [`tasks.web.octane: false`](/reference/manifest#tasks-web): classic mode runs `frankenphp php-server` and needs no octane package.
- **Scheduler (supercronic)** — it runs the freshly-built image and fails if `supercronic` isn't on the `PATH`. Every app hosts the scheduler somewhere (there's no opt-out), and the failure this prevents is **silent**: busybox `crond` — the obvious fallback already in the base image — ignores crontabs not owned by root without logging a word, so an image without supercronic deploys green, stays healthy, and simply never fires a scheduled job.
- **SSR (Node)** — when [`tasks.web.ssr`](/reference/manifest#tasks-web) is on, it runs the freshly-built image and fails if `node` isn't on the `PATH`. Like the scheduler check, this matters because a missing SSR runtime is otherwise **silent** — Inertia falls back to client-side rendering and the web tier stays healthy on `/up`, so the deploy goes green with SSR quietly off.

The image probes run the image (rather than grepping the Dockerfile), so they see the resolved base image and multi-stage `COPY --from` layers too — no false negatives.

YOLO doesn't assert the image's base runtime (e.g. that PHP is present) — Docker makes that the app's to swap, and a genuinely missing PHP runtime already fails loudly when `octane:start` crash-loops and the deployment rolls back.

## Processes in the container

Every app runs three roles — web, the queue worker, and the scheduler — and by default they all share the one web container:

```yaml
tasks:
  web:
    port: 8000
```

- **Web** always runs the web server. By default that's `php artisan octane:start` serving Laravel Octane: `octane:start` boots whichever server your app's `OCTANE_SERVER` names; `yolo init` seeds `OCTANE_SERVER=frankenphp` in your `.env` to match the scaffolded Dockerfile. The server is an app concern, not infrastructure YOLO injects — to run a different Octane server, change the base image **and** `OCTANE_SERVER` together. Set [`tasks.web.octane: false`](/reference/manifest#tasks-web) to run FrankenPHP in **classic mode** (`frankenphp php-server`) instead — per-request boot, no resident app — for an app that isn't Octane-safe yet; the `frankenphp` binary ships in the base image independent of `laravel/octane`, so it serves even with no octane package.
- **The queue worker** runs `queue:work`, bundled in the web container until you extract it.
- **The scheduler** runs [supercronic](https://github.com/aptible/supercronic), firing `php artisan schedule:run` every minute, bundled until you extract it. (YOLO uses cron, not `schedule:work`, so the scheduler survives `SIGTERM` cleanly — supercronic stops scheduling on stop and waits out the in-flight run.)
- **`ssr: true`** adds Inertia's SSR renderer — see [Inertia SSR](#inertia-ssr) below.

::: tip Independent task groups
Run web in isolation by extracting the **worker tier**: add a top-level `tasks.queue` block and the queue worker and scheduler move to their own service, leaving the web container running just the web server. Add `tasks.scheduler` too for a dedicated singleton cron. Where each role runs is derived from which blocks you've added; see [Where each role runs](/reference/manifest#where-each-role-runs) and [Scaling](/guide/scaling).
:::

## Inertia SSR

Set [`tasks.web.ssr: true`](/reference/manifest#tasks-web) to server-render your Inertia + Vue pages (better SEO, faster first paint). YOLO adds an `ssr` program to supervisord that runs `php artisan inertia:start-ssr` — a Node process listening on `127.0.0.1:13714`. PHP calls it on localhost for each render, so **SSR is always bundled in the web container** — never its own service. YOLO injects `INERTIA_SSR_ENABLED=true` (unless your `.env` already sets it); the render URL comes from Inertia's default `config/inertia.php`.

Two things are on you:

1. **A Node runtime in your image.** The scaffolded Dockerfile already installs `nodejs`, so SSR works out of the box. If you've slimmed it out (or moved to a base image without Node), add it back — after building the image `yolo build` runs it and checks `node` is on the `PATH` when `ssr` is on, and **hard-fails the build** if it's missing (see [Runtime checks](#runtime-checks)).
2. **An SSR bundle from your build.** Your `npm run build` must emit the SSR bundle (`bootstrap/ssr/`) — that's standard [Inertia SSR](https://inertiajs.com/server-side-rendering) setup in your `vite.config.js`. The bundle is copied into the image automatically (it isn't excluded by `.dockerignore` or the build's `node_modules` cleanup).

If the SSR process is down, Inertia falls back to client-side rendering, so the app keeps serving — the ALB health check stays on PHP's `/up` and isn't coupled to SSR. supervisord restarts a crashed renderer automatically.

## The `.dockerignore`

The scaffolded `.dockerignore` trims the build context but **deliberately keeps** a few things the image depends on:

- `.env` — the environment's file, baked in at build time
- `vendor` — installed by your `build` hook, not the Dockerfile
- `public/build` — compiled Vite assets
- `docker/` — the generated supervisord config(s) and the scheduler's crontab
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

The scheduler gets special treatment: supercronic stops launching new `schedule:run` ticks the instant `SIGTERM` lands, and the in-flight run gets the rest of the stop window — by default everything Fargate allows, since its stop overlaps the other programs' rather than delaying them. All of a container's graces share Fargate's 120s `stopTimeout` ceiling; a combination that overcommits it fails the deploy with an error instead of being silently cut short at the wire.
