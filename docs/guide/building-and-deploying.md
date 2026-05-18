# Building & Deploying

## Build

Create a deployment-ready build:

```bash
yolo build <environment>
```

This prepares a deployment directory in `./yolo` based on the build commands defined in your manifest.

## Deploy

Deploy a build to AWS:

```bash
yolo deploy <environment>
```

## Build + Deploy

Build and deploy in a single command:

```bash
yolo deploy <environment>
```

When no existing build is found, the deploy command automatically runs the build step first.

## App Version

Specify a version with the `--app-version` flag:

```bash
yolo deploy production --app-version=25.3.1
```

The version must start with `year.week` (e.g. `25.3` for the third week of 2025). After that, use whatever convention you like — a common approach is to increment a third digit for each release.

::: tip
Because the app version uses UTC by default, you may want to set the `timezone` option in your manifest to your team's timezone to prevent validation errors at the start of the week.
:::

## Deploy Lifecycle

After your code lands on each instance, YOLO handles the entire startup sequence — you don't need to script any of it in your manifest. The full sequence runs on every instance after every deploy:

1. **Provision directories** — creates `~/yolo/{app}/` and `/var/log/yolo/{app}/`
2. **Run your manifest deploy hooks** (`deploy`, `deploy-queue`, `deploy-web`, `deploy-all` — see below)
3. **Sync supervisor configs** — writes per-group worker/cron definitions based on your current manifest
4. **Sync nginx + PHP configuration** — writes site configs and PHP-FPM pools
5. **Restart services** — `supervisorctl reread && supervisorctl update && supervisorctl start all`, plus `systemctl restart php8.3-fpm` and `systemctl restart nginx`
6. **Warm the application** — hits each tenant's root URL so the first real request isn't a cold start
7. **Re-register with the load balancer** (when using `with-load-balancing` strategy)

The practical consequences for what you put in your manifest:

- **You don't need `queue:restart`.** Supervisor restarts every queue worker as part of step 5 — workers come back running the new code automatically. Adding `queue:restart` to a deploy hook is a no-op at best.
- **You don't need to bounce PHP-FPM or nginx.** Both restart automatically in step 5.
- **Route/config/view caching belongs in `build:`, not `deploy:`.** These are deterministic per build — bake them into the artefact once during build (`php artisan optimize` in `build:` is fine) rather than re-running them on every instance at deploy time. They can also fail loudly (e.g. route closures break `route:cache`) — catch those failures in CI, not on a production instance mid-deploy.
- **`php artisan optimize` in `deploy:` is harmless but redundant** if you already cache in `build:`. Pick one layer.

## Manifest Deploy Hooks

The manifest supports four hooks, each targeting a different scope. The minimal stub ships just the first two:

```yaml
deploy:       # runs once per deployment, on the scheduler instance
  - php artisan migrate --force

deploy-all:   # runs on every instance after deploy hooks
  - php artisan optimize
```

`deploy` is for **once-per-deployment** work — migrations, search-index rebuilds, tenant bootstrapping. The scheduler is the chosen instance because there's only ever one.

`deploy-all` is for **per-instance** work that needs to touch every box — typically nothing, since the lifecycle above handles most of it.

`deploy-queue` and `deploy-web` exist as **escape hatches** for the rare case where one server group needs setup the platform can't anticipate. There's no canonical use — if you reach for them, double-check that what you want isn't already handled by the lifecycle above.

## Targeted Deploys

Deploy to specific server groups with the `--only` option:

```bash
# Deploy a hotfix to web only
yolo deploy production --only web

# Deploy to web and queue, skip scheduler
yolo deploy production --only web,queue
```

Groups that are disabled (`false`) or combined will be skipped automatically, even if specified in `--only`.

## Deployment Status

Track in-progress deployments:

```bash
yolo deploy:status <environment>
```
