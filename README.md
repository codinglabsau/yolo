<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

# YOLO

YOLO deploys Laravel applications to AWS Fargate.

> [!IMPORTANT]
> This package is in active development - contributions are welcome!

## Documentation

Full documentation — getting started, the command reference, and the manifest reference — lives at
**[codinglabsau.github.io/yolo](https://codinglabsau.github.io/yolo/)**. You can have a Laravel app live on Fargate in
under an hour by following the [Getting Started guide](https://codinglabsau.github.io/yolo/guide/getting-started).

The docs are a VitePress site under [`docs/`](docs/) and deploy to GitHub Pages on every push to `main`.

## Composer pinning

For new apps and Fargate canaries:

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main"
  }
}
```

For existing EC2/ASG consumers (frozen, maintenance-only):

```json
{
  "require": {
    "codinglabsau/yolo-alpha": "v1.0.0-alpha.34"
  }
}
```

Consumers migrating from `yolo-alpha` to `yolo` 1.0 install both packages side-by-side during the cutover window, then drop `yolo-alpha` once on Fargate.

## YOLO 1.0 in one line

```bash
yolo init && yolo sync production && yolo deploy production
```

`yolo deploy` builds and pushes the image, then ships it. Pass `--app-version=<tag>` (e.g. a GitHub release name) to stamp the build with a specific tag instead of an auto-generated timestamp.

## Deploying from GitHub Actions (OIDC)

Deploy from CI with short-lived, keyless credentials — no `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` in repo secrets.

Each environment declares the **ref it deploys from**. That one setting drives the CI deployer role's OIDC trust (and, later, the local deploy guard). It defaults to the `main` branch, so the common case needs no config at all:

| Ref | OIDC `sub` scope | Typical use |
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

`repository` is inferred from your git origin (or `GITHUB_REPOSITORY` in CI) — set `repository: org/repo` per environment only to override (monorepo / fork).

When a GitHub repository is detected, `yolo sync:iam` provisions (and keeps in sync with the deploy steps):

- the account's GitHub Actions OIDC identity provider (`token.actions.githubusercontent.com`), an account-level singleton;
- a deployer role `yolo-{env}-{app}-deployer` whose trust only lets the environment's repo + ref assume it; and
- a tightly-scoped permission policy covering exactly what `yolo deploy` does (ECR push, ECS register/update, `iam:PassRole` on the task + execution roles, S3 env/asset access, Route 53 record changes).

In the consumer workflow, request the OIDC token and assume the role — no stored secrets:

```yaml
permissions:
  id-token: write
  contents: read

steps:
  - uses: aws-actions/configure-aws-credentials@v4
    with:
      role-to-assume: arn:aws:iam::<account-id>:role/yolo-production-myapp-deployer
      aws-region: <region>
  - run: vendor/bin/yolo deploy production
```

For the strongest production gate, pair `tag: 'v*'` with a GitHub protected-tag ruleset (only maintainers may cut `v*` tags) — the AWS trust then just confirms "a tag push from this repo", and GitHub enforces who can trigger it.

In CI, YOLO defers to the AWS SDK's default credential chain, so all three auth methods work out of the box with no extra config — OIDC (above), AWS IAM Identity Center (SSO), and legacy long-lived static access keys. Static keys still work but emit a warning nudging you towards OIDC.

## Pre-1.0 alpha documentation

The EC2/ASG `yolo-alpha` documentation lives in its own repo: [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha). Existing consumers should reference that repo.

## Contributing

`yolo-alpha`: bug fixes only. Pull requests against [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) are welcome for production-safe patches. No new features.

`yolo` (this repo, `main`): in active development. Open an issue on this repo or coordinate with @stevethomas before submitting PRs.

## License

MIT — see [LICENSE.md](LICENSE.md).
