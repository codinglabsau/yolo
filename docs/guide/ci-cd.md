# CI/CD

Deploy from CI with short-lived, **keyless** credentials via GitHub OIDC. YOLO provisions a GitHub Actions OIDC trust and a tightly-scoped deployer role; your workflow assumes that role at runtime — nothing to store in your repo.

## Declare the ref each environment deploys from

Each environment declares the **git ref it deploys from**. That single setting drives the deployer role's OIDC trust. It defaults to the `main` branch, so the common case needs no configuration at all:

| Manifest | OIDC `sub` scope | Typical use |
|---|---|---|
| `branch: main` (default) | `…:ref:refs/heads/main` | push to a branch — e.g. staging |
| `tag: 'v*'` (`true` = any tag) | `…:ref:refs/tags/v*` | tag push — e.g. production |

Staging-on-`develop`, production-on-tag:

```yaml
environments:
  staging:
    branch: develop           # deploys on push to develop
  production:
    tag: 'v*'                 # only a v* tag can assume the prod role
```

`repository` is inferred from your git origin (or `GITHUB_REPOSITORY` in CI). Set `repository: org/repo` per environment only to override it — for a monorepo or fork.

## What `yolo sync` provisions for CI

When a GitHub repository is detected, `yolo sync` sets up the OIDC trust across the scopes:

- **`sync:account`** provisions the account's GitHub Actions OIDC identity provider (`token.actions.githubusercontent.com`) — an account-level singleton shared by every app.
- **`sync:environment`** provisions the env-shared **`yolo-{env}-observer`** policy — read-only access scoped to exactly the services YOLO provisions (not AWS's everything). It's the inspection surface the pre-deploy [in-sync check](#yolo-deploy-refuses-to-run-against-drift) reads, shared by every app's deployer role (and reusable by an operator/admin role).
- **`sync:app`** provisions the deployer role `yolo-{env}-{app}-deployer`, whose primary trust lets only the environment's repo + ref assume it from CI (keyless OIDC); a second trust statement permits same-account assumption, so a developer running `yolo deploy` / `build` / `run` locally mints exactly this role's deploy policy on top of their own identity (the **Deployer tier** — capped to deploy, never their broader profile). It carries a permission policy scoped to exactly what `yolo deploy` writes (ECR push, ECS register/update, `iam:PassRole` on the task + execution roles, S3 env/asset access, Route 53 record changes, and — when the app uses the shared Valkey cache — reading the cluster endpoint to bake `REDIS_HOST`). It also attaches the env's `yolo-{env}-observer` policy, so the deployer inherits the read surface the in-sync check needs without any new direct grant — and never the blast radius of AWS-managed `ReadOnlyAccess`. Object reads in the observer are scoped to non-secret config (the env manifest + app claim files), so the deploy role still can't read the env-shared `.env` or other apps' secrets.

A plain `yolo sync <env>` does all of it. Re-run it whenever you change the `branch`/`tag`/`repository` for an environment.

## The consumer workflow

Request the OIDC token and assume the deployer role — no stored secrets:

```yaml
permissions:
  id-token: write
  contents: read

steps:
  - uses: actions/checkout@v4

  - uses: aws-actions/configure-aws-credentials@v4
    with:
      role-to-assume: arn:aws:iam::<account-id>:role/yolo-production-myapp-deployer
      aws-region: <region>

  - run: vendor/bin/yolo deploy production --no-progress
```

In CI, YOLO defers to the AWS SDK's default credential chain, so the assumed-role credentials are picked up automatically — no `YOLO_PRODUCTION_AWS_PROFILE` needed.

::: tip Strongest production gate
Pair `tag: 'v*'` with a GitHub protected-tag ruleset (only maintainers may cut `v*` tags). The AWS trust then just confirms "a tag push from this repo", and GitHub enforces who can trigger it.
:::

## `yolo deploy` refuses to run against drift

Before it builds, `yolo deploy` runs a full `sync --check` (account → environment → app) and **aborts if anything has drifted** from the manifest. A deploy only rolls a new task-definition revision onto the *existing* infrastructure — it never reconciles it — so this stops a deploy landing on a stale target group, a changed task role, an un-provisioned listener, or a shared foundation (VPC/ALB/OIDC) that no longer matches `yolo.yml`. It also fires sync's claim gate, so an app that claims an env service the environment doesn't offer (e.g. typesense) is refused with a precise message. The check plans only (never writes), runs *before* the build so a drift fails fast without burning one, prints the full diff, then refuses with `Refusing to deploy — <env> is not in sync`. Reconcile with `yolo sync <env>` and redeploy.

Whole-stack rather than app-only is deliberate: a deploy is the natural — and for most setups the only — moment drift is checked, so the gate covers the shared foundation the app sits on, not just the app's own slice. This is why the deployer role attaches the env's `yolo-{env}-observer` policy: the check reads across every service YOLO provisions, under whatever identity is deploying. No extra workflow step is needed — the gate is part of `yolo deploy` itself.

| Exit code | Meaning |
|---|---|
| `0` | In sync — the build and rollout proceed. |
| non-zero | Drift detected (deploy refused), **or** the check itself errored — bad credentials, an AWS API failure, an invalid manifest, or a claimed service the environment doesn't offer. |

The fix is always the same: run `yolo sync <env>` to reconcile, then deploy again. The same check is available standalone as [`yolo sync <env> --check`](/reference/commands#sync-options) if you ever want to probe for drift without deploying — but you don't need to wire anything up; the gate is part of every deploy.

## Other auth methods

The default credential chain means both auth methods work with no extra config:

- **OIDC** (above) — recommended.
- **AWS IAM Identity Center (SSO)**.
