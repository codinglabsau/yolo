# Status

YOLO 1.0 (Fargate/ECS) is in active development on `main`. The pre-1.0 EC2/ASG codebase has been extracted to [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) (frozen, maintenance-only).

## Repos

- **[`codinglabsau/yolo`](https://github.com/codinglabsau/yolo)** — YOLO 1.0 Fargate development. **Empty skeleton as of 2026-05-18.** Commands land incrementally toward the MVP milestone.
- **[`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha)** — EC2/ASG codebase, extracted from the original `1.x` branch. Bug fixes only for LP and other pre-1.0 consumers. **No new features.** Initial tag is `v1.0.0-alpha.34` (matching the original release identity). Patches land as `v1.0.0-alpha.35`, `.36`, etc.

## Composer pinning

| Consumer | Constraint | Why |
|---|---|---|
| Live Platforms (production, pre-migration) | `"codinglabsau/yolo-alpha": "v1.0.0-alpha.34"` | Pinned to the frozen alpha. Migrates during the cutover window. |
| Live Platforms (during cutover window, ~6 weeks) | Both `"codinglabsau/yolo": "dev-main"` + `"codinglabsau/yolo-alpha": "v1.0.0-alpha.34"` | Dual-stack — alpha drives v1 CodeDeploy/ASG deploys, `yolo` drives Fargate deploys. |
| Live Platforms (post-cutover) | `"codinglabsau/yolo": "^1.0"` | `yolo-alpha` dropped from `composer.json`. |
| CL marketing site | `"codinglabsau/yolo": "dev-main"` | First Fargate canary (no alpha in the path — direct from Vapor). |
| Convict Records | `"codinglabsau/yolo": "dev-main"` | Second Fargate canary (direct from Vapor). |
| New client apps | `"codinglabsau/yolo": "dev-main"` | YOLO 1.0 from day one. |

## Naming

- The pre-1.0 codebase was tagged `v1.0.0-alpha.34` — it never reached 1.0. Naming the extracted package `yolo-alpha` reflects that truthfully. The same `v1.0.0-alpha.34` tag is preserved on the extracted repo so the release identity is unbroken.
- YOLO 1.0 is the first stable release. The "v2" framing used internally during the 2026-05 pivot is dropped — to anyone discovering YOLO post-migration, `codinglabsau/yolo 1.0.0` is the first version that exists.

## Migration approach

- `yolo` stays on `dev-main` until LP is migrated and stability is proven across the canaries (CL marketing, Convict Records). 1.0.0 GOLD tag follows LP cutover completion.
- Migration tooling is minimal — one `audit:legacy` command for visibility, plus single-purpose `migrate:*` commands built ad-hoc as the LP cutover surfaces specific needs.
- LP transitions via ALB weighted target groups (gradual traffic shift), not a hard cutover. See [docs/migrating-from-alpha.md](docs/migrating-from-alpha.md) for phases.

## What lives where

Once the YOLO 1.0 MVP ships, the command surface will look roughly like:

```bash
yolo init             # scaffold yolo.yml + Dockerfile
yolo build            # docker build + push to ECR
yolo sync <env>       # provision VPC / ECS cluster / task def / service / ALB target group
yolo deploy <env>     # aws ecs update-service with new task revision
yolo run <env> <cmd>  # one-off command via ECS Exec (replaces the alpha's `yolo command`)
yolo audit:legacy     # detect yolo-alpha resources by tag during LP migration
```

Schema and command details will firm up as the MVP lands.
