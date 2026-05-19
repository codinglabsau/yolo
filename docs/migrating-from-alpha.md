# Migrating from `yolo-alpha` to YOLO 1.0

> **Status:** Living document. Updated as YOLO 1.0 matures and the migration surfaces specific needs. Authoritative source for any consumer planning a `yolo-alpha` → `yolo` 1.0 transition.

## Background

The pre-1.0 YOLO codebase (EC2/ASG deployer, tagged `v1.0.0-alpha.34`) was extracted to [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) so it can run side-by-side with YOLO 1.0 during the LP cutover window. Both packages install cleanly into the same `composer.json`:

- `codinglabsau/yolo-alpha` → bin `yolo-alpha`, namespace `Codinglabs\YoloAlpha` — drives EC2/ASG/CodeDeploy deploys
- `codinglabsau/yolo` → bin `yolo`, namespace `Codinglabs\Yolo` — drives Fargate/ECS deploys

## Why migrate

- **Operational simplicity** — no AMIs, no ASGs, no supervisor/nginx configs to maintain, no SSH key rotation. Container as deployment unit.
- **Cost efficiency at low traffic** — Fargate Spot for non-customer-facing tasks (queue, scheduler) typically beats EC2 baseline for the same workload.
- **App isolation** — containers prevent per-app PHP/extension/composer dependency conflicts.
- **Octane fit** — FrankenPHP container is one Dockerfile line; the alpha's Octane support required nginx-fronting + supervisor configs.
- **HA without scheduler-isolation gymnastics** — ECS Service handles task replacement; multi-instance scaling doesn't require `->onOneServer()` discipline.

## Migration phases (overview)

The full transition runs in five phases. Earlier phases unlock later ones. Each phase is reversible until you decommission `yolo-alpha` resources in Phase 5.

1. **Pre-migration prep** — containerise the app, validate stateless assumptions, install both packages side-by-side
2. **Fargate provisioned alongside the alpha stack** — both stacks exist in AWS, EC2/ASG still serves 100% traffic
3. **ALB weighted traffic shift** — gradual cutover, observable, rollback-able
4. **Cutover** — 100% Fargate, EC2 ASGs scaled to zero as warm rollback
5. **Decommission** — manual cleanup via `yolo audit:legacy` + AWS console; drop `yolo-alpha` from `composer.json`

## Phase 1 — Pre-migration prep

Per-app checklist before any infrastructure work:

- [ ] **Author Dockerfile** — `FROM dunglas/frankenphp:php8.4` is the YOLO 1.0 default. `yolo init` (post-MVP) will scaffold this.
- [ ] **Audit local filesystem writes** — anything writing to `/var/www/storage` or `/home/ubuntu` must go to S3 or accept ephemeral loss. Sessions, file cache, logs all need attention.
- [ ] **Sessions go to Redis/database** — file sessions break across containers
- [ ] **Cache driver supports atomic locks** — Redis, database, dynamodb, memcached. File cache won't work for distributed locks. Only matters if you'll scale beyond 1 task.
- [ ] **All Pest/PHPUnit tests pass in container** — `docker compose run --rm app vendor/bin/pest`
- [ ] **Octane decision** — defer if migrating from `yolo-alpha`; Octane should be a separate change after Fargate cutover stabilises
- [ ] **Install both packages side-by-side** — see Phase 2 below

## Phase 2 — Provision Fargate alongside the alpha stack

In the app's `composer.json`, add `codinglabsau/yolo` alongside the existing alpha pin:

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main",
    "codinglabsau/yolo-alpha": "v1.0.0-alpha.34"
  }
}
```

Run `composer update codinglabsau/yolo codinglabsau/yolo-alpha`. Both CLIs are now installed:

```
vendor/bin/yolo         # YOLO 1.0 — Fargate deploys
vendor/bin/yolo-alpha   # alpha — CodeDeploy/ASG deploys
```

Provision the Fargate stack:

```bash
vendor/bin/yolo build production
vendor/bin/yolo sync production
```

`sync` is idempotent and adopts existing AWS resources where it can (VPC, subnets, RDS, S3 bucket, Route 53 records). It creates new resources for the Fargate stack: ECR repo, ECS cluster, task definition, ECS service, and a new target group attached to the existing ALB.

Update CI to deploy both stacks on every push to main during the cutover window:

```yaml
- name: Deploy alpha stack (EC2/ASG)
  run: vendor/bin/yolo-alpha deploy production

- name: Deploy YOLO 1.0 stack (Fargate)
  run: vendor/bin/yolo deploy production
```

Order matters: deploy the alpha stack first (slower — full CodeDeploy cycle, 5–10 min), then Fargate (~30s ECS rolling update). If the alpha deploy fails, Fargate doesn't run — keeps both stacks at the same code SHA.

Both stacks now exist in AWS:
- Alpha EC2 ASG + target group serving 100% real traffic via existing ALB listener rules
- Fargate service + target group registered with the ALB but receiving 0% traffic

## Phase 3 — ALB weighted traffic shift

The ALB listener that currently routes all traffic to the alpha target group gets a weighted configuration:

```bash
# Initial: 95% alpha, 5% Fargate
# Adjust via AWS console under Listener rules → Edit → Forward to (weighted)
```

Cadence (rough):
- Week 1: 95/5 — watch error rate, p95 latency, queue depth
- Week 2: 80/20 — if metrics clean
- Week 3: 50/50
- Week 4: 20/80
- Week 5: 0/100, EC2 ASGs scaled to zero as instant rollback

Rollback is a single console edit at any point: adjust the weight back to the alpha target group.

## Phase 4 — Cutover

When 100% of traffic is on Fargate and stable for at least a week:

- Set EC2 ASGs `MinSize=0, MaxSize=0, DesiredCapacity=0`
- Existing EC2 resources persist but consume no compute
- Monitor for an additional week as warm rollback insurance
- CI workflow can drop the `yolo-alpha deploy` step (Fargate-only deploys from here)

## Phase 5 — Decommission

Run the audit:

```bash
vendor/bin/yolo audit:legacy production
```

Confirms what alpha resources still exist. Then manually delete via AWS console:
- EC2 ASGs
- Launch templates
- CodeDeploy app + deployment groups
- The alpha target group (the one attached to instance IDs, not Fargate IPs)
- Any alpha-specific IAM roles no longer referenced

Re-run `yolo audit:legacy` to verify nothing leaked.

Drop `codinglabsau/yolo-alpha` from `composer.json`:

```json
{
  "require": {
    "codinglabsau/yolo": "^1.0"
  }
}
```

`composer update --lock`. The alpha binary and namespace are gone from the app.

## Rollback strategy

| Phase | Rollback mechanism |
|---|---|
| 2 | Remove `codinglabsau/yolo` from `composer.json`, no Fargate resources created |
| 3 | ALB weight back to the alpha target group, single console edit |
| 4 | Scale EC2 ASGs back up, shift ALB weight |
| 5 | No clean rollback — alpha resources deleted. Only proceed when confident. |

## Specific app notes

### Live Platforms (LP)

LP-specific migration notes accumulate here as the migration surfaces them.

> _Empty until LP migration begins. Issues uncovered during LP staging migration land here._

### Coding Labs marketing site

CL marketing migrates from Vapor → YOLO 1.0 directly (no alpha in the path). Different shape from `yolo-alpha` → `yolo` migration — the dual-package install pattern doesn't apply.

> _Update with anything discovered during the CL canary deploy._

### Convict Records

Convict Records migrates from Vapor → YOLO 1.0 directly (no alpha in the path). Same shape as CL marketing.

> _Update with anything discovered during the Convict canary deploy._

## Migration commands

The `migrate:*` namespace is intentionally minimal. YOLO 1.0 ships one diagnostic command and adds others only when specific migration friction warrants automation.

- **`yolo audit:legacy <env>`** — detect `yolo-alpha`-provisioned resources by tag, report with cost estimates. Ships in MVP.
- _Additional `migrate:*` commands documented here as they're built._

## References

- [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) — for anyone still operating EC2/ASG resources
