# Environment Files

Your application's runtime configuration — database credentials, API keys, cache and queue settings — lives in a per-environment `.env` file stored in S3, **not** committed to your repository. YOLO pulls it down at build time and bakes it into the container image.

Each environment has its own file, named `.env.<environment>`:

```
.env.production
.env.staging
```

`yolo init` adds these to your `.gitignore` so they're never committed.

## Initial setup

After `yolo init` you'll have a starter `.env.production`. Fill it in with your real production configuration (copy from an existing `.env` if you have one), then push it to S3.

## Push

Upload your local environment file to the S3 artefacts bucket:

```bash
yolo env:push production
```

YOLO downloads the current remote version first and shows a **diff** of exactly which keys are being added, changed, or removed — then asks you to confirm before uploading. If there's no remote file yet, it uploads straight away.

This is the safe way to change production config: edit `.env.production` locally, review the diff, confirm.

## Pull

Download the current remote environment file:

```bash
yolo env:pull production
```

This writes `.env.production` to your project root. Use it to review what's currently deployed, or to pick up changes a teammate pushed before you edit.

::: warning
`env:pull` overwrites your local `.env.production`. Pull before you edit so you're working from the current remote version, not a stale one.
:::

## How it's used at deploy time

You don't reference the env file in your `deploy` command — it's automatic. During `yolo build`, YOLO retrieves `.env.<environment>` from S3, stamps in the build's `APP_VERSION` (and `ASSET_URL`, mirrored into `VITE_ASSET_URL` so Vite sees the same prefix, when a CDN is configured), and bakes it into the image as `/app/.env`. Your `build` hooks (e.g. `npm run build`) run against that environment, and the running container uses it directly.

The bucket itself (`yolo-{account-id}-{env}-{app}-artefacts`) is provisioned by [`yolo sync`](/guide/provisioning). Bucket names carry the AWS account id because S3 names are globally unique across every account — without it, the first account to claim a name owns it and every other account's sync fails.
