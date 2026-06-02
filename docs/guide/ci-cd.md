# CI/CD

Deploy from CI with short-lived, **keyless** credentials — no `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` in your repo secrets. YOLO provisions a GitHub Actions OIDC trust and a tightly-scoped deployer role; your workflow assumes that role at runtime.

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

When a GitHub repository is detected, `yolo sync` sets up the OIDC trust across two scopes:

- **`sync:account`** provisions the account's GitHub Actions OIDC identity provider (`token.actions.githubusercontent.com`) — an account-level singleton shared by every app.
- **`sync:app`** provisions the deployer role `yolo-{env}-{app}-deployer`, whose trust only lets the environment's repo + ref assume it, plus a permission policy scoped to exactly what `yolo deploy` does (ECR push, ECS register/update, `iam:PassRole` on the task + execution roles, S3 env/asset access, Route 53 record changes, and — when the app uses the shared Valkey cache — reading the cluster endpoint to bake `REDIS_HOST`).

A plain `yolo sync <env>` does both. Re-run it whenever you change the `branch`/`tag`/`repository` for an environment.

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

## Gate CI on drift with `yolo sync --check`

`yolo deploy` ships your code; `yolo sync --check` guards the infrastructure behind it. Run it in CI to fail a pipeline the moment your live AWS resources drift from `yolo.yml` — someone hand-edited a resource in the console, or a manifest change merged without anyone running `yolo sync`.

`--check` runs the same read-only plan pass as [`--dry-run`](/guide/provisioning) and prints the same diff, but **never applies** and exits non-zero when there are pending changes:

| Exit code | Meaning |
|---|---|
| `0` | In sync — no pending changes. |
| non-zero | Drift detected, **or** the check itself errored (bad credentials, an AWS API failure, an invalid manifest). |

Either way, a non-zero exit means the job should fail and a human should read the printed plan.

::: warning Use read-capable credentials, not the deployer role
`--check` is a `sync` operation, not a deploy: it `Describe`s every resource across the stack to compute drift, so it needs **read access to the whole provisioned surface** — far broader than the deploy-scoped deployer role above. Run the check with a read-capable identity (your SSO role, or one granted AWS's managed `ReadOnlyAccess`), separate from the narrow deploy role. Pointed at the deployer role it would `AccessDenied` and fail for the wrong reason.
:::

```yaml
# A scheduled (or pre-deploy) drift check — note the read-capable role
permissions:
  id-token: write
  contents: read

steps:
  - uses: actions/checkout@v4

  - uses: aws-actions/configure-aws-credentials@v4
    with:
      role-to-assume: arn:aws:iam::<account-id>:role/<read-capable-role>
      aws-region: <region>

  - run: vendor/bin/yolo sync production --check --no-progress
```

See the [`sync` reference](/reference/commands#sync-options) for the full option list.

## Other auth methods

The default credential chain means all three auth methods work with no extra config:

- **OIDC** (above) — recommended.
- **AWS IAM Identity Center (SSO)**.
- **Legacy long-lived static access keys** — still work, but YOLO emits a warning nudging you toward OIDC.
