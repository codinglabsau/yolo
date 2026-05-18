# Migrating from YOLO v1 to v2

> **Status:** Living document. Updated as YOLO v2 matures and the migration surfaces specific needs. Authoritative source for any consumer planning a v1 → v2 transition.

## Why migrate

- **Operational simplicity** — no AMIs, no ASGs, no supervisor/nginx configs to maintain, no SSH key rotation. Container as deployment unit.
- **Cost efficiency at low traffic** — Fargate Spot for non-customer-facing tasks (queue, scheduler) typically beats EC2 baseline for the same workload.
- **App isolation** — containers prevent per-app PHP/extension/composer dependency conflicts.
- **Octane fit** — FrankenPHP container is one Dockerfile line; v1 Octane support required nginx-fronting + supervisor configs.
- **HA without scheduler-isolation gymnastics** — ECS Service handles task replacement; multi-instance scaling doesn't require `->onOneServer()` discipline.

## Migration phases (overview)

The full transition runs in five phases. Earlier phases unlock later ones. Each phase is reversible until you decommission v1 resources in Phase 5.

1. **Pre-migration prep** — containerise the app, validate stateless assumptions
2. **Fargate provisioned alongside v1** — both stacks exist in AWS, v1 still serves 100% traffic
3. **ALB weighted traffic shift** — gradual cutover, observable, rollback-able
4. **Cutover** — 100% Fargate, v1 ASGs scaled to zero as warm rollback
5. **Decommission v1** — manual cleanup via `yolo audit:legacy` + AWS console

## Phase 1 — Pre-migration prep

Per-app checklist before any infrastructure work:

- [ ] **Author Dockerfile** — `FROM dunglas/frankenphp:php8.3` is the v2 default. `yolo init` (post-MVP) will scaffold this.
- [ ] **Audit local filesystem writes** — anything writing to `/var/www/storage` or `/home/ubuntu` must go to S3 or accept ephemeral loss. Sessions, file cache, logs all need attention.
- [ ] **Sessions go to Redis/database** — file sessions break across containers
- [ ] **Cache driver supports atomic locks** — Redis, database, dynamodb, memcached. File cache won't work for distributed locks. Only matters if you'll scale beyond 1 task.
- [ ] **All Pest/PHPUnit tests pass in container** — `docker compose run --rm app vendor/bin/pest`
- [ ] **Octane decision** — defer if migrating from v1; Octane should be a separate change after Fargate cutover stabilises

## Phase 2 — Provision Fargate alongside v1

In the app's `composer.json`, switch the YOLO constraint:

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main"
  }
}
```

Run `composer update codinglabsau/yolo`. The v2 CLI is now installed.

Provision the v2 stack:

```bash
yolo build production
yolo sync production
```

`sync` is idempotent and adopts existing AWS resources where it can (VPC, subnets, RDS, S3 bucket, Route 53 records). It creates new resources for the Fargate stack: ECR repo, ECS cluster, task definition, ECS service, and a new target group attached to the existing ALB.

Both stacks now exist in AWS:
- v1 EC2 ASG + target group serving 100% real traffic via existing ALB listener rules
- v2 Fargate service + target group registered with the ALB but receiving 0% traffic

## Phase 3 — ALB weighted traffic shift

The ALB listener that currently routes all traffic to the v1 target group gets a weighted configuration:

```bash
# Initial: 95% v1, 5% v2
# Adjust via AWS console under Listener rules → Edit → Forward to (weighted)
```

Cadence (rough):
- Week 1: 95/5 — watch error rate, p95 latency, queue depth
- Week 2: 80/20 — if metrics clean
- Week 3: 50/50
- Week 4: 20/80
- Week 5: 0/100, EC2 ASGs scaled to zero as instant rollback

Rollback is a single console edit at any point: adjust the weight back to v1.

## Phase 4 — Cutover

When 100% of traffic is on Fargate and stable for at least a week:

- Set v1 ASGs `MinSize=0, MaxSize=0, DesiredCapacity=0`
- Existing v1 EC2 resources persist but consume no compute
- Monitor for an additional week as warm rollback insurance

## Phase 5 — Decommission v1

Run the audit:

```bash
yolo audit:legacy production
```

Confirms what v1 resources still exist. Then manually delete via AWS console:
- v1 EC2 ASGs
- v1 launch templates
- v1 CodeDeploy app + deployment groups
- v1 target group (the one attached to instance IDs, not Fargate IPs)
- Any v1-specific IAM roles no longer referenced

Re-run `yolo audit:legacy` to verify nothing leaked.

## Rollback strategy

| Phase | Rollback mechanism |
|---|---|
| 2 | Don't update composer constraint, no v2 resources created |
| 3 | ALB weight back to v1, single console edit |
| 4 | Scale v1 ASGs back up, shift ALB weight |
| 5 | No clean rollback — v1 resources deleted. Only proceed when confident. |

## Specific app notes

### Live Platforms (LP)

LP-specific migration notes accumulate here as the migration surfaces them.

> _Empty until LP migration begins. Issues uncovered during LP staging migration land here._

### Coding Labs marketing site

CL marketing migrates from Vapor → YOLO v2 directly (no v1 in the path). Different shape from v1 → v2 migration.

> _Update with anything discovered during the CL canary deploy._

## Migration commands

The `migrate:*` namespace is intentionally minimal. v2 ships one diagnostic command and adds others only when specific migration friction warrants automation.

- **`yolo audit:legacy <env>`** — detect v1 resources by tag, report with cost estimates. Ships in MVP.
- _Additional `migrate:*` commands documented here as they're built._

## References

- [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353) — milestones, MVP scope, LP migration phases
- [`STATUS.md`](../STATUS.md) — branch / composer pinning at a glance
- [v1 documentation (1.x branch)](https://github.com/codinglabsau/yolo/tree/1.x) — for anyone still operating v1 resources
