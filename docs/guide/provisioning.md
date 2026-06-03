# Provisioning

`yolo sync` reconciles the AWS resources your app needs with what you've declared in `yolo.yml`. It's idempotent: it looks at what already exists, computes the difference, and makes only the API calls needed to close the gap. Run it as often as you like.

```bash
yolo sync production
```

## Scope-first provisioning

YOLO groups every resource by **ownership scope** — the blast radius if it changes. Each scope has exactly one writer, so an app deploy can never mutate shared infrastructure:

| Command | Scope | Blast radius | Provisions |
|---|---|---|---|
| `yolo sync:account <env>` | **Account** | the whole AWS account | GitHub OIDC provider |
| `yolo sync:environment <env>` | **Environment** | every app in the environment | VPC, subnets, internet gateway & routes, RDS security group, SNS alarm topic, shared ECS task & execution IAM roles, the ALB and its `:80`/`:443` listeners |
| `yolo sync:app <env>` | **App** | one app | S3 buckets, app IAM (deployer role/policy), ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, hosted zone & ACM certificate, SQS queues, CloudWatch dashboard — plus, for web apps, the shared [Valkey cache](#cache-and-sessions) (default-on; opt out via `cache.store`). Sessions ride the same Valkey cluster by default, so they need no provisioning of their own |

The bare `yolo sync` runs all three **in dependency order** — account, then environment, then app:

```bash
yolo sync production   # account → environment → app
```

`sync:app` only *additively attaches* to shared infrastructure (its SNI certificate and listener rule on the environment's `:443` listener, its `3306` ingress rule on the shared RDS security group, its `6379` ingress rule on the shared cache security group). It never modifies the shared resource itself, so the environment tier stays the single writer.

The shared **Valkey cache** is env-scoped but bootstrapped from `sync:app` by exception (like the RDS security group), because its security group needs this app's task SG to authorise. The first web app to sync creates the cluster (cache defaults on); later apps find it and just wire their env. **Sessions reuse the same cluster** (on a separate logical database), so there's no extra session infrastructure to provision.

::: tip Why scopes matter
Several apps can share one environment's VPC and load balancer. Because `sync:app` only attaches and never mutates, deploying app B can't break app A's networking. When you're iterating on one app, `sync:app` is faster than a full `sync` — the account and environment tiers rarely change.
:::

## Plan, confirm, apply

`sync` never surprises you. It runs as a three-step flow:

1. **Plan** — YOLO inspects live AWS state and computes what would change, rendering it grouped by scope. Brand-new resources are listed under **Will create** (one `+` line each); drift on existing resources is shown under **Pending changes** as per-attribute diffs (`current → desired`).
2. **Confirm** — you're shown the plan and asked to approve. If nothing has drifted, it short-circuits with **"Already in sync"** and exits without touching anything.
3. **Apply** — only the changed steps run.

### Preview with `--dry-run`

To see the plan without the confirm/apply step at all:

```bash
yolo sync production --dry-run
```

This computes and prints the full diff but makes no changes. It's the safe way to see what a `sync` would do before you commit — always dry-run first against an account you care about.

### Gate CI on drift with `--check`

`--check` is the machine-readable counterpart to `--dry-run`. It runs the same plan pass and prints the same diff, but never applies and **exits non-zero when the environment has drifted** (and `0` when it's already in sync):

```bash
yolo sync production --check
```

Wire it into CI to fail a pipeline the moment infrastructure drifts from the manifest — someone hand-edited a resource, or a `sync` was never run after a manifest change. A non-zero exit also covers a dry-run that errored (bad credentials, an AWS API failure, an invalid manifest); in every case CI should stop and a human should look at the printed plan.

### Skip the prompt with `--force`

In automation, skip the interactive confirmation:

```bash
yolo sync production --force
```

### Narrow to one tenant

For a multi-tenant app, limit the per-tenant steps to a single tenant (e.g. during a single-tenant cutover):

```bash
yolo sync:app production --tenant=acme
```

See the [`sync` command reference](/reference/commands#yolo-sync) for every option.

## Cache and sessions

Fargate tasks share nothing on their local filesystem, so a cache or session that lives there breaks the moment an app runs more than one task. **Web apps therefore get a shared cache and session store by default** — opt out per app, rather than opt in to not being broken.

### Cache

A web app defaults to [`cache.store: redis`](/reference/manifest#cache) — a shared **ElastiCache for Valkey** cache, one cluster per environment, isolated per app by a `REDIS_PREFIX`. It's a single `cache.t4g.micro` node (a standard single instance; auto-failover/Multi-AZ off, ~A$11/mo) with `allkeys-lru` eviction, locked by a security group that only allows `6379` from the Fargate task SG. The container env is wired with `CACHE_STORE=redis`, `REDIS_HOST`, `REDIS_PORT` and `REDIS_PREFIX` — each only if your `.env` doesn't already set it. Set `cache.store: file|database|array` to opt out.

Scaling is a deliberate vertical resize (a brief ~60s endpoint blip, data retained), not autoscaling — a cache evicts rather than runs out, and at this size the dollars don't justify a control loop.

### Sessions

A web app defaults to [`session.driver: redis`](/reference/manifest#session); set the key to override. YOLO provisions only what the chosen driver needs:

- **`redis`** (default) — reuses the Valkey cache (needs `cache.store: redis`, the web-app default — YOLO hard-fails if you opt the cache out without re-pinning the session driver). Sessions land on Laravel's stock `default` redis connection (**DB 0**), separate from the cache connection (**DB 1**): same Valkey instance, different keyspace, so `cache:clear` never wipes sessions. YOLO injects `SESSION_DRIVER=redis` only — `SESSION_CONNECTION` is deliberately left unset so the null connection resolves to `default`. This relies on cluster-mode-disabled Valkey (the YOLO default), where logical databases exist. Strong read-after-write consistency (~1&nbsp;ms) means a freshly written session is readable immediately. The single node has no session HA — a node loss logs users out.
- **`database` / `cookie` / `file`** — no infrastructure; YOLO just pins `SESSION_DRIVER`.

### Cache high availability

The single cache node is a sound default — a node loss flushes the cache (it repopulates from source) and, since sessions ride the same Valkey cluster, logs users out; both are rare and low-stakes for most apps. If you need session durability across a node loss, add a Multi-AZ replica to the cluster (`IncreaseReplicaCount` + automatic failover) — a follow-up, not the default. If you only need graceful **cache** degradation without paying for a replica, use Laravel's first-party [`failover` cache store](https://laravel.com/docs/cache#cache-failover) (`CACHE_STORE=failover`, `stores: ['redis', 'database']`) — it falls through when the node is unreachable.

::: warning Failover isn't write-back
Writes that land in the fallback store during an outage are **not** synced back to Valkey when it recovers, so it's a degradation cushion, not a replica. It also only covers the **cache** store — sessions on the redis driver still depend on the node being up, so for session HA add a Multi-AZ replica rather than relying on failover.
:::

## Auditing what's deployed

`yolo audit` is the read-only counterpart to `sync`. It queries every resource tagged `yolo:environment=<env>` and classifies each one:

```bash
yolo audit production
```

| Status | Meaning |
|---|---|
| `ok` | Accounted for — `yolo:app` points at a live app, or it carries a `yolo:scope=env`/`=account` marker (declared shared infra). |
| `drift` | `yolo:app` points at an app whose ECS cluster is gone — leftover resources from a removed app. |
| `rogue` | Tagged for the environment but with **no** YOLO ownership marker — hand-rolled infrastructure or alpha-era debris in the environment's namespace. |

::: tip The per-app dashboard isn't audited
`sync:app` also generates a CloudWatch dashboard (`yolo-<env>-<app>-dashboard`) panelling the app's ECS service, ALB, SQS queues, CloudFront, S3 and logs, plus an RDS panel derived from `DB_HOST`. CloudWatch dashboards can't carry tags, so it's a read-only convenience that **won't** show up in `yolo audit`.
:::

Like sync, audit is scope-grouped — narrow it with `audit:environment <env>` or `audit:app <env> <app>`, and add `--drift` to show only the drifted rows:

```bash
yolo audit production --drift
yolo audit:app production myapp
```

Full details in the [audit command reference](/reference/commands#yolo-audit).
