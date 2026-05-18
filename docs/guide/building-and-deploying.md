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

## Deploy Commands

The manifest supports separate deploy commands for each server group:

```yaml
deploy:       # runs on scheduler instances
  - php artisan migrate --force

deploy-queue: # runs on queue instances
  - php artisan queue:restart

deploy-web:   # runs on web instances
  - php artisan route:cache

deploy-all:   # runs on all instances
  - php artisan optimize
```

Use `deploy` for commands that should only run once per deployment (like migrations). Use `deploy-all` for commands that need to run on every instance.

## Targeted Deploys

Deploy to specific server groups with the `--only` option:

```bash
# Deploy a hotfix to web only, without restarting queue workers
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
