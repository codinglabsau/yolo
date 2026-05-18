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

## Deploy Hooks

The manifest supports four hooks, each targeting a different scope. The stub ships the first two:

```yaml
deploy:       # runs once per deployment, on the scheduler instance
  - php artisan migrate --force

deploy-all:   # runs on every instance after deploy hooks
  - php artisan optimize
```

`deploy` is for **once-per-deployment** work — migrations, search-index rebuilds, tenant bootstrapping. The scheduler is the chosen instance because there's only ever one.

`deploy-all` is for **per-instance** work that needs to touch every box.

`deploy-queue` and `deploy-web` target only the queue or web server groups respectively, for setup that needs to run on one group but not the others.

### Patterns to avoid

After your deploy hooks run, YOLO restarts supervisor, PHP-FPM, and nginx, then warms the app before traffic returns. A few commands you might be tempted to add belong outside the deploy hooks because the platform already handles them — or because there's a better layer:

| Don't add to a deploy hook | Reason |
|---|---|
| `php artisan queue:restart` | Queue workers are restarted automatically when supervisor reloads — this is a no-op. |
| `systemctl restart php-fpm` / `nginx -s reload` | YOLO restarts both automatically. |
| `php artisan route:cache`, `config:cache`, `view:cache`, `event:cache` | Deterministic per build — put them in `build:` so the cache ships with the artefact. Failures (e.g. route closures breaking `route:cache`) surface in CI instead of mid-deploy on a production instance. |

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
