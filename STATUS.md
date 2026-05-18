# Status

YOLO is mid-pivot from v1 (EC2/ASG) to v2 (Fargate/ECS).

## Branches

- **`main`** — v2 Fargate development. **Empty skeleton as of 2026-05-18.** Commands land via the [YOLO v2 Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353) MVP milestone.
- **`1.x`** — v1 maintenance only. Bug fixes for LP and other v1 consumers. **No new features.** Tagged releases: `v1.0.0-alpha.34` is the last v1 alpha. Patches land as `v1.0.0-alpha.N+1`.

## Composer pinning

| Consumer | Constraint | Why |
|---|---|---|
| Live Platforms (production) | `"codinglabsau/yolo": "v1.0.0-alpha.34"` | Pinned to a known v1 alpha. Migrates to v2 when staging proves stable. |
| CL marketing site | `"codinglabsau/yolo": "dev-main"` | First v2 consumer (canary). |
| New client apps | `"codinglabsau/yolo": "dev-main"` | v2 from day one. |

## Migration approach

- v2 stays in alpha until LP is migrated and stability is proven. No v2.0.0 GOLD tag in the short term.
- Migration tooling is minimal — one `audit:legacy` command for visibility, plus single-purpose `migrate:*` commands built ad-hoc as the LP cutover surfaces specific needs.
- LP transitions via ALB weighted target groups (gradual traffic shift), not a hard cutover.

## What lives where

Once v2 ships its MVP, the new command surface will look roughly like:

```bash
yolo init             # scaffold yolo.yml + Dockerfile
yolo build            # docker build + push to ECR
yolo sync <env>       # provision VPC / ECS cluster / task def / service / ALB target group
yolo deploy <env>     # aws ecs update-service with new task revision
yolo run <env> <cmd>  # one-off command via ECS Exec (replaces v1's `yolo command`)
yolo audit:legacy     # detect v1 resources by tag during LP migration
```

Schema, command details, and progress in the [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353).
