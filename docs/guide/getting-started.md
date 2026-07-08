# Getting Started

This guide takes you from an empty Laravel app to a live deployment on AWS Fargate. With an AWS account ready, it takes under an hour — most of which is AWS provisioning your infrastructure while you wait.

You don't need prior YOLO knowledge. Each step links to a deeper page if you want the detail, but you can follow this straight through.

## Prerequisites

- **PHP 8.3+** and **Composer**
- **Docker**, running locally — YOLO builds your container image on your machine
- An **AWS account** and an **access key for your IAM user** — step 3's `yolo configure` turns it into a named profile with short-lived sessions. (Already have a named profile in `~/.aws/config`? That works too — just don't use the `default` profile.)
- **For a public app:** a domain you can manage in **Route 53** on that account. (You can skip this and run a [headless app](/guide/domains#headless-apps) with no public URL.)

::: tip The whole thing in one line
Once you've done the setup below, day-to-day it's just:

```bash
yolo sync production && yolo deploy production
```
:::

## 1. Install

From your Laravel project root:

```bash
composer require codinglabsau/yolo
```

The CLI is now at `vendor/bin/yolo`. Run it with no arguments to list every command:

```bash
vendor/bin/yolo
```

::: tip
Add `./vendor/bin` to your `PATH` and you can type `yolo` instead of `vendor/bin/yolo`. The rest of these docs use the short form.
:::

## 2. Initialise

```bash
yolo init
```

This interactive command scaffolds everything you need:

- **`yolo.yml`** — your manifest, pre-filled with the environment you named (e.g. `production`) from your answers (app name, AWS account ID, region, domain).
- **`Dockerfile`** and **`.dockerignore`** — sensible defaults you can customise. See [The Container Image](/guide/images).
- **`.env.<environment>`** — a starter environment file.
- It appends `.yolo` and your environment's `.env` file (plus `.env.staging`/`.env.production`) to your `.gitignore`, and offers to install the [AWS Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) (needed later for `yolo run`).

Open `yolo.yml` and skim it — it's short and commented. You can tweak it now or come back later. The full key reference is in the [Manifest reference](/reference/manifest).

## 3. Point YOLO at AWS

YOLO authenticates to AWS using a **named profile per environment**. `init` offers this step at the end of its run; if you skipped it there, set it up now — profile, short-lived-session credential helper, and the `.env` wiring in one interactive run:

```bash
yolo configure production
```

It ends with a live STS verification, so when it goes green this machine is provably ready. See [`yolo configure`](/reference/commands#yolo-configure) for each step, and [Developer Credentials](/guide/credentials) for the full team-onboarding picture (IAM users, access tiers, MFA).

Already have a named profile? Point YOLO at it in your local `.env` instead:

```bash
# .env
YOLO_PRODUCTION_AWS_PROFILE=myapp-production
```

The pattern is `YOLO_<ENVIRONMENT>_AWS_PROFILE`. Either way, before YOLO touches AWS it calls STS to confirm the profile resolves to the same account ID you declared in `yolo.yml` — so a wrong profile fails fast instead of provisioning into the wrong account.

::: warning
Don't point this at your `default` profile. YOLO rejects it deliberately — a named profile makes "which account am I about to change?" unambiguous.
:::

## 4. Push your environment file

Your application's runtime `.env` lives in S3, not in the image source. Fill in `.env.production` (database, cache, mail, etc.), then push it:

```bash
yolo env:push production
```

YOLO shows a diff of what's changing and asks for confirmation before uploading. This `.env.production` is baked into the image at build time. More in [Environment Files](/guide/environment-files).

## 5. Provision your infrastructure

This is the big one — `yolo sync` creates the VPC, load balancer, ECS cluster, IAM roles, S3 buckets, certificate, and DNS for your app:

```bash
yolo sync production
```

YOLO **always shows the plan before it touches anything** — a diff grouped by scope (account → environment → app) of exactly what would be created or changed — then asks you to confirm. So to preview, just run it and read the plan; decline (or Ctrl-C) if it's not what you expected, confirm when it looks right. The first sync provisions a fair amount and can take several minutes (ACM certificate validation and load balancer provisioning are the slow parts). It's safe to re-run any time — a second `sync` on an unchanged manifest reports "already in sync" and does nothing.

See [Provisioning](/guide/provisioning) for what each scope creates and how the plan/confirm/apply flow works.

## 6. Deploy

```bash
yolo deploy production
```

`deploy` builds your container image, pushes it to ECR, registers a new task definition, runs your deploy hooks (e.g. `php artisan migrate`), rolls the ECS service over to the new version, and waits for it to go healthy before pointing DNS at it. If the new version fails its health checks, the [deployment circuit breaker](/guide/building-and-deploying#zero-downtime-rollout) rolls it back automatically.

When it finishes, your app is live. 🚀

## 7. Visit your app

Once Route 53 has propagated, open your domain in a browser. Need a shell inside the running container?

```bash
yolo run production                              # interactive shell
yolo run production --command="php artisan tinker"
```

(ECS Exec is on by default — `enable-execute-command` defaults to `true` — so this just needs the Session Manager plugin installed locally. See [`yolo run`](/reference/commands#yolo-run).)

## Where to next

You now have the full loop: **sync** infrastructure, **deploy** code. From here:

- [The Container Image](/guide/images) — what's in the Dockerfile and what YOLO generates
- [Environment Files](/guide/environment-files) — managing `.env` across environments
- [Provisioning](/guide/provisioning) — the scope model and `sync` in depth
- [Building & Deploying](/guide/building-and-deploying) — the build/deploy pipeline and `yolo run`
- [Domains](/guide/domains) · [Multi-Tenancy](/guide/multi-tenancy) · [CI/CD](/guide/ci-cd)
- [Command reference](/reference/commands) · [Manifest reference](/reference/manifest)
