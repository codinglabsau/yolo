# The Container Image

YOLO deploys your app as a single Docker image to Fargate. That one image runs everything — the web server and, optionally, queue workers and the scheduler — supervised by [supervisord](https://supervisord.org/).

You own a small `Dockerfile`; YOLO generates the moving parts (entrypoint, process config) into the build context at build time. This page is the **contract** between the two.

## What `yolo init` scaffolds

`yolo init` writes a `Dockerfile` and `.dockerignore` to your project root. The default Dockerfile is built on [FrankenPHP](https://frankenphp.dev/) and looks like this:

```dockerfile
FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache git supervisor \
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

ENTRYPOINT ["/app/.yolo-entrypoint.sh"]
CMD ["supervisord", "-n"]
```

Customise it freely — add PHP extensions, system packages, a different base image. Just keep the contract below intact.

## What YOLO generates

During `yolo build`, YOLO writes two files into the build context that your Dockerfile copies in:

| File | Purpose |
|---|---|
| `.yolo-entrypoint.sh` | The container entrypoint. Runs your `deploy-all` hooks (e.g. `php artisan optimize`) on startup, then `exec`s the `CMD` (supervisord). It traps `SIGTERM` so the web tier keeps serving across the ALB drain window before forwarding the stop signal. |
| `docker/supervisord.conf` | The supervisord program tree, generated from your `tasks.web.*` settings — FrankenPHP/Octane for web, plus `queue:work` and the scheduler if you've enabled them. A crontab is generated alongside it when the scheduler is on. |

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
   CMD ["supervisord", "-n"]
   ```
4. **Expose the web port**, and make sure it matches `tasks.web.port` in your manifest (default `8000`). The ALB health-checks this port at `/up` (Laravel's built-in [health route](https://laravel.com/docs/deployment#the-health-route)) — override the path or timing via [`tasks.web.health-check.*`](/reference/manifest#tasks-web-health-check).
5. Have **`supervisor`** installed (the default Dockerfile installs it via `apk add`).

## Processes in the container

What runs inside the container is driven by `tasks.web` in your manifest:

```yaml
tasks:
  web:
    port: 8000
    queue: true       # run queue:work alongside the web server
    scheduler: true   # run the Laravel scheduler (cron + schedule:run)
```

- **Web** always runs — `php artisan octane:start` serving Laravel Octane. `octane:start` boots whichever server `OCTANE_SERVER` names; YOLO defaults that to `frankenphp` (matching the scaffolded Dockerfile's base image), so the zero-config image just works. To run a different Octane server, swap the base image and set `OCTANE_SERVER` in your `.env` — YOLO only supplies the default when you haven't.
- **`queue: true`** adds a `queue:work` program.
- **`scheduler: true`** adds a busybox `crond` that fires `php artisan schedule:run` every minute. (YOLO uses cron, not `schedule:work`, so the scheduler survives `SIGTERM` cleanly.)

::: tip Independent task groups
Today web, queue, and scheduler share one container and scale together. Splitting them into independent ECS services (so you can scale the web tier without duplicating the scheduler) is on the roadmap — the manifest reserves `tasks.queue` / `tasks.scheduler` for it.
:::

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
