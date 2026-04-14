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

## Deployment Status

Track in-progress deployments:

```bash
yolo deploy:status <environment>
```
