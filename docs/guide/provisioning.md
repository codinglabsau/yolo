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
| `yolo sync:environment <env>` | **Environment** | every app in the environment | VPC, subnets, internet gateway & routes, RDS security group, SNS alarm topic, the shared ECS execution IAM role, the env config bucket (`yolo-{account-id}-{env}-config` — [the environment's declaration](#the-environment-declaration): env manifest + env-shared `.env`), the env logs bucket (`yolo-{account-id}-{env}-logs` — the shared ALB's access logs under `alb/`, everything expiring after 90 days), the IVS event-logging pipeline (when the env manifest declares `services.ivs` — the `aws.ivs` event stream is account-wide, so the pipeline is env-shared), the ALB and its `:80`/`:443` listeners, the [WAF](#web-application-firewall) fronting the ALB |
| `yolo sync:app <env>` | **App** | one app | S3 buckets, app IAM (deployer role/policy, the per-app ECS task role + any [`task-role-policies`](/reference/manifest#task-role-policies)), ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, hosted zone & ACM certificate, SQS queues, CloudWatch dashboard — plus, for web apps, the shared [Valkey cache](#cache-and-sessions) (default-on; opt out via `cache.store`). Sessions ride the same Valkey cluster by default, so they need no provisioning of their own |

The bare `yolo sync` runs all three **in dependency order** — account, then environment, then app:

```bash
yolo sync production   # account → environment → app
```

`sync:app` only *additively attaches* to shared infrastructure (its SNI certificate and listener rule on the environment's `:443` listener, its `3306` ingress rule on the shared RDS security group, its `6379` ingress rule on the shared cache security group). It never modifies the shared resource itself, so the environment tier stays the single writer.

The shared **Valkey cache** is env-scoped but bootstrapped from `sync:app` by exception (like the RDS security group), because its security group needs this app's task SG to authorise. The first web app to sync creates the cluster (cache defaults on); later apps find it and just wire their env. **Sessions reuse the same cluster** (on a separate logical database), so there's no extra session infrastructure to provision.

::: tip Why scopes matter
Several apps can share one environment's VPC and load balancer. Because `sync:app` only attaches and never mutates, deploying app B can't break app A's networking. When you're iterating on one app, `sync:app` is faster than a full `sync` — the account and environment tiers rarely change.
:::

## The environment declaration

App manifests declare what each **app** needs. The environment-shared tier has a declaration of its own: two files in the env config bucket (`yolo-{account-id}-{env}-config`), living in S3 rather than any app's repo precisely *because* they're shared — no single repo owns them, and every syncing app must see the same truth.

- **`yolo-environment-{environment}.yml`** — [the env manifest](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml): the environment's canonical service domain and its env-shared services. `yolo.yml` is the app; `yolo-environment-production.yml` is the production environment. Seeded with defaults by the environment's first `sync` and **never touched by sync again** — every later edit is yours, made through the pull/push flow below.
- **`.env`** — the env-shared secrets channel, the environment-tier sibling of each app's `.env.{environment}`. It holds *generated* service secrets (created on demand by the services that need them) and anything an env-shared service should read at provision time.

The edit flow mirrors app env files:

```bash
yolo environment:manifest:pull production    # → yolo-environment-production.yml (gitignored)
# edit
yolo environment:manifest:push production    # validated, key-level diff, confirm
yolo sync:environment production             # from any app in the environment
```

The env-shared `.env` moves the same way via `environment:env:pull` / `environment:env:push` (local copy `.env.environment.production`, gitignored).

Because every `sync:environment` pulls the manifest fresh from S3 and converges toward it, the environment's desired state has a **single source of truth** — apps pinned to different `codinglabsau/yolo` releases reconcile toward the same declared state instead of fighting over compiled-in defaults, within the schema each release knows: a manifest carrying keys from a newer release hard-fails older binaries with an upgrade hint, so update yolo across the environment's app repos before pushing a new key. Changing an env service's size is a file edit and a sync, not a delete-and-recreate.

::: warning Access is the boundary
S3 read on the env config bucket is what gates env-secret control. Deploying an app never requires it — the barrier to mutate the environment is deliberately higher than the barrier to ship an app.
:::

## Web application firewall

Every environment with a load balancer gets a managed [AWS WAF](https://docs.aws.amazon.com/waf/latest/developerguide/what-is-aws-waf.html) web ACL on its ALB — automatically, with no manifest key. It's compulsory infrastructure, like the ALB itself: one web ACL protects every app sharing the load balancer.

YOLO owns the **policy** — a baseline of AWS-managed protections, a per-IP rate limit, and a high-risk-country block — and reconciles it on every sync. You own the **operational lists**:

- The **allow** and **block IP sets** are seeded empty for you to fill (known-good IPs to allow; abusive sources to block). Their contents are **create-only** — an entry you add in the console survives every subsequent `sync`.
- The **country block** is seeded with a sensible default and is likewise yours to re-scope; it's **seed-only**, so your edits stick.
- Any **rule you add by hand** is preserved too — YOLO only ever rewrites the rules it owns.

Tune the rest in the AWS console; YOLO won't undo it.

## Plan, confirm, apply

`sync` never surprises you. It runs as a three-step flow:

1. **Plan** — YOLO inspects live AWS state and computes what would change, rendering it grouped by scope. Brand-new resources are listed under **Will create** (one `+` line each); drift on existing resources is shown under **Pending changes** as per-attribute diffs (`current → desired`).
2. **Confirm** — you're shown the plan and asked to approve. If nothing has drifted, it short-circuits with **"Already in sync"** and exits without touching anything.
3. **Apply** — only the changed steps run.

The plan is **always** shown before the confirm, so there's no separate preview mode — to see what a `sync` would do, just run it and read the plan, then decline (or Ctrl-C) instead of confirming. Nothing is written until you approve.

### Gate CI on drift with `--check`

`--check` runs the same read-only plan pass and prints the same diff, but never applies and **exits non-zero when the environment has drifted** (and `0` when it's already in sync):

```bash
yolo sync production --check
```

Wire it into CI to fail a pipeline the moment infrastructure drifts from the manifest — someone hand-edited a resource, or a `sync` was never run after a manifest change. A non-zero exit also covers a plan that errored (bad credentials, an AWS API failure, an invalid manifest); in every case CI should stop and a human should look at the printed plan.

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

- **`redis`** (default) — reuses the Valkey cache (needs `cache.store: redis`, the web-app default; YOLO hard-fails if you opt the cache out without re-pinning the session driver). YOLO injects `SESSION_DRIVER=redis` only. Strong read-after-write consistency (~1&nbsp;ms) means a freshly written session is readable immediately — no stale-read flicker right after login. The single node has no session HA — a node loss logs users out.
- **`database` / `cookie` / `file`** — no infrastructure; YOLO just pins `SESSION_DRIVER`.

#### Sessions and cache share the node, not the keyspace

With both on `redis`, sessions and cache run on the **same** Valkey instance but on **separate Redis logical databases** — so they never collide and a `cache:clear` never touches sessions:

| Backend | Laravel redis connection | Logical DB | Database env (default) |
|---|---|---|---|
| Cache (`cache.store: redis`) | `cache` | **DB 1** | `REDIS_CACHE_DB` (1) |
| Sessions (`session.driver: redis`) | `default` | **DB 0** | `REDIS_DB` (0) |

You get this with no dedicated session connection because three stock Laravel defaults stack:

1. `config/database.php` ships two redis connections out of the box — `default` (database `REDIS_DB`, default 0) and `cache` (database `REDIS_CACHE_DB`, default 1).
2. `config/cache.php`'s `redis` store uses the `cache` connection → DB 1.
3. The redis **session** handler routes by `session.connection`, *not* the cache store's connection: `SessionManager::createRedisDriver()` resolves the `redis` cache store and then **overrides** its connection with `config('session.connection')`. With `SESSION_CONNECTION` unset that's `null`, which Laravel's redis manager resolves to the `default` connection → DB 0.

That's why YOLO injects `SESSION_DRIVER=redis` **only** and deliberately leaves `SESSION_CONNECTION` unset — and it relies on **cluster-mode-disabled** Valkey (the YOLO default), since logical databases don't exist in cluster mode.

::: warning The split is inherited from your app's config, not enforced by YOLO
YOLO injects `REDIS_HOST` / `REDIS_PORT` / `REDIS_PREFIX` but **not** `REDIS_DB`, `REDIS_CACHE_DB`, or `SESSION_CONNECTION` — the DB-0/DB-1 separation comes entirely from stock `config/database.php` + `config/cache.php`. If your app has dropped the `cache` connection, pointed both connections at the same database, or set `REDIS_DB`/`REDIS_CACHE_DB` to the same value, sessions and cache collapse onto one keyspace and a `cache:clear` will flush live sessions. Keep the two stock connections — or set `SESSION_CONNECTION` to a dedicated connection if you want sessions on a specific DB.
:::

### Cache high availability

The single cache node is a sound default — a node loss flushes the cache (it repopulates from source) and, since sessions ride the same Valkey cluster, logs users out; both are rare and low-stakes for most apps. If you need session durability across a node loss, add a Multi-AZ replica to the cluster (`IncreaseReplicaCount` + automatic failover) — a follow-up, not the default. If you only need graceful **cache** degradation without paying for a replica, use Laravel's first-party [`failover` cache store](https://laravel.com/docs/cache#cache-failover) (`CACHE_STORE=failover`, `stores: ['redis', 'database']`) — it falls through when the node is unreachable.

::: warning Failover isn't write-back
Writes that land in the fallback store during an outage are **not** synced back to Valkey when it recovers, so it's a degradation cushion, not a replica. It also only covers the **cache** store — sessions on the redis driver still depend on the node being up, so for session HA add a Multi-AZ replica rather than relying on failover.
:::

## Auditing what's deployed

`yolo audit` is the read-only counterpart to `sync`. It's an **ownership/inventory** check — it queries every resource tagged `yolo:environment=<env>` and asks "is this accounted for?". It does **not** inspect a resource's configuration; comparing live attributes against the manifest is `sync`'s job (and `sync`'s "drift" — config superseded — is a different thing from anything here).

```bash
yolo audit production
```

There are two statuses, and a **Reason** column explains every `unexpected` row:

| Status | Meaning |
|---|---|
| `ok` | Accounted for — `yolo:app` points at a live app, or it carries a `yolo:scope=env`/`=account` marker (declared shared infra). |
| `unexpected` | In the environment's tag namespace but not accounted for. See the Reason. |

| Reason (on `unexpected`) | Meaning |
|---|---|
| `no ownership tag` | **No** YOLO ownership marker (`yolo:app`/`yolo:scope`) — hand-rolled infrastructure, or alpha-era debris in the namespace. |
| `service no longer provisioned` | YOLO-owned, but of an AWS service YOLO no longer provisions — there's no `Resources/` class for it, so a sync would never create it. Left behind when support for a service is removed (the DynamoDB sessions table after DynamoDB sessions were dropped is the canonical case). Safe to delete once confirmed. |
| `app cluster gone` | YOLO-owned, managed service, but `yolo:app` points at an app whose ECS cluster no longer exists — leftover resources from a removed app. |

The `service no longer provisioned` check is driven by the catalogue of services YOLO has resource classes for, which mirrors the `src/Resources/*` directories. That makes it correct by construction: a managed service is never false-flagged, and the day a service is dropped its leftover resources surface automatically — no allow-list to keep in sync by hand.

::: tip The per-app dashboard isn't audited
`sync:app` also generates a CloudWatch dashboard (`yolo-<env>-<app>-dashboard`) panelling the app's ECS service, ALB, SQS queues, CloudFront, S3 and logs, plus an RDS panel derived from `DB_HOST`. CloudWatch dashboards can't carry tags, so it's a read-only convenience that **won't** show up in `yolo audit`.
:::

Like sync, audit is scope-grouped — narrow it with `audit:environment <env>` or `audit:app <env> <app>`, and add `--unexpected` to show only the rows needing attention:

```bash
yolo audit production --unexpected
yolo audit:app production myapp
```

Full details in the [audit command reference](/reference/commands#yolo-audit).
