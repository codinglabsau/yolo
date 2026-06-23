# Building & Deploying

With your infrastructure provisioned by [`yolo sync`](/guide/provisioning), shipping code is one command:

```bash
yolo deploy production
```

`deploy` builds the image and ships it. You rarely need `build` on its own — `deploy` runs it for you.

## What `deploy` does

`yolo deploy` runs the build, then the rollout, end to end:

1. **Build** — runs the full [`build`](#the-build) pipeline below (image built and pushed to ECR).
2. **Push assets** — uploads compiled assets to the S3 asset bucket (served via CloudFront when configured).
3. **Register task definition** — registers a new ECS task definition revision pointing at the freshly pushed image.
4. **Run deploy hooks** — runs your manifest's `deploy` commands (e.g. `php artisan migrate --force`) as a one-off ECS task before traffic shifts.
5. **Update the service** — points the ECS service at the new revision and starts the rolling deployment.
6. **Wait for healthy** — polls until the new tasks pass their health checks.
7. **Point DNS** — UPSERTs the Route 53 record(s) once the deployment is healthy.
8. **Recap** — prints a summary of what's now running (each service's task spec, count, scaling, and new revision) plus a link to the app's CloudWatch dashboard — the same view [`yolo status`](/reference/commands#yolo-status) shows.

To watch a rollout as it happens, or check what's running at any time, run [`yolo status <env>`](/reference/commands#yolo-status) — a live dashboard of services, load, scaling, and any in-progress deploy.

## The build

`yolo build production` prepares and packages the image:

1. Purge the build directory and stage a clean copy of your app.
2. Pull `.env.<environment>` from S3 and stamp in `APP_VERSION` (and `ASSET_URL`, mirrored into `VITE_ASSET_URL` for Vite, if a CDN is configured).
3. Run your manifest's `build` hooks (`composer install`, `npm run build`, …). With [Inertia SSR](/guide/images#inertia-ssr) enabled, this is also where `npm run build` produces the SSR bundle that gets baked into the image.
4. Generate the entrypoint and supervisord config (see [The Container Image](/guide/images)), gated by an Octane preflight: YOLO **hard-fails the build** if `laravel/octane` isn't in your committed `composer.lock` production requirements — the web role runs `octane:start`, so a missing (or `require-dev`-only) octane would crash-loop the container on boot. (Skipped when [`tasks.web.octane: false`](/reference/manifest#tasks-web), where the web tier runs FrankenPHP classic mode and needs no octane package.)
5. Log in to ECR, build the Docker image, probe it for the runtimes it needs — `supercronic` always (the scheduler's cron), `node` when `tasks.web.ssr` is on — **hard-failing the build** if one is missing (see [Runtime checks](/guide/images#runtime-checks)), then push it.

The image-building steps (4–5) only run when your manifest declares `tasks` — with none declared, the build stops after staging the build directory (env file baked, build hooks run) and no image is produced.

## Zero-downtime rollout

The rollout uses the **ECS deployment circuit breaker**. New tasks must pass their ALB health checks before the old ones are drained and stopped; if the new version fails to stabilise, ECS automatically rolls back to the previous task definition. Combined with the [graceful-shutdown drain window](/guide/images#graceful-shutdown), a healthy deploy serves traffic with no dropped requests — and a broken one never takes the service down.

## App version

Every build is stamped with an `APP_VERSION`. By default it's a timestamp in the form `y.W.N.Hi` (two-digit year, ISO week, ISO weekday, hour-minute) — for example `26.22.5.1430`. Override it to stamp a meaningful tag, such as a GitHub release name:

```bash
yolo deploy production --app-version=26.22.1
```

The version **must start with the current `year.week` prefix** (e.g. `26.22`) — this keeps versions monotonic and prevents accidental stale tags. Both `26.22` and the non-zero-padded `26.<week>` forms are accepted.

::: tip
The week prefix is computed in your manifest's `timezone` (defaulting to UTC). Set `timezone` to your team's timezone so a version cut just before midnight on a Sunday doesn't trip the validation. See [`timezone`](/reference/manifest#timezone).
:::

## Rolling back

To return to a version you've already shipped, use [`yolo rollback`](/reference/commands#yolo-rollback) — it re-deploys an image that's still in ECR, skipping the build entirely:

```bash
yolo rollback production
```

The interactive picker lists your recent deployments (newest first, the running one marked `(current)`); pick one and it re-points the service through the same circuit-breaker-guarded rollout as a deploy — re-running your `deploy` hooks against the rolled-back image. Code and assets are versioned and immutable, so they revert cleanly — but the **database does not** (`migrate` is forward-only and never reverts the schema), so `rollback` warns before continuing: a rollback past a destructive migration can break against the old code.

::: warning
Rollback reverts code and assets, never the schema. Roll back across additive migrations freely; across a destructive one, only after confirming the old code runs against the new schema.
:::

## Hooks: `build` vs `deploy` vs `deploy-all`

Three manifest arrays run shell commands at different points:

| Hook | Runs | Where | Use for |
|---|---|---|---|
| `build` | At build time, on your machine | the build context | `composer install`, `npm run build`, asset compilation |
| `deploy` | Once per deploy, before traffic shifts | a one-off ECS task | `php artisan migrate --force` |
| `deploy-all` | On every container start | the entrypoint | `php artisan optimize` (cache config/routes/views) |

The `deploy` task templates on your management-tier service — a dedicated `scheduler` if you've extracted one, else a standalone `queue`, else `web` (the same `scheduler → queue → web` order `yolo run` uses). It's a one-off task, so it just runs the hooks once and exits. It runs at a fixed 1 vCPU / 2 GiB regardless of that service's task size, so migrations and other hooks aren't throttled by a thin management tier (a `queue`/`scheduler` defaults to 0.25 vCPU).

```yaml
build:
  - composer install --no-dev --optimize-autoloader
  - npm ci
  - npm run build
deploy:
  - php artisan migrate --force
deploy-all:
  - php artisan optimize
```

## Hiding progress output

All stepped commands accept `--no-progress` to suppress the live progress UI — useful in CI logs:

```bash
yolo deploy production --no-progress
```

## Running commands in the container

To open a shell or run a one-off command inside a **running** task (via ECS Exec):

```bash
yolo run production                                   # interactive shell
yolo run production --command="php artisan tinker"    # one-off command
yolo run production --command="php artisan queue:restart" --group=web,queue
```

This needs `tasks.web.enable-execute-command: true` in your manifest and the AWS Session Manager plugin installed locally (`yolo init` offers to install it). See [`yolo run`](/reference/commands#yolo-run).
