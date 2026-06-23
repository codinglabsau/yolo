---
name: yolo
description: Operate a YOLO-deployed Laravel app on AWS Fargate — read live service/infra state and reason about health, drift, scaling and cost. Use when asked to check, audit, diagnose, or propose changes to a YOLO environment (status, deploys, autoscaling, infra drift, manifest hygiene). Read-first; every mutation is human-gated.
allowed-tools:
  - Bash
  - Read
  - Grep
  - Glob
  - Edit
  - Write
---

# YOLO

[YOLO](https://github.com/codinglabsau/yolo) deploys Laravel apps to **AWS Fargate (ECS)**. This skill is the *brain*; the `vendor/bin/yolo` CLI is a **dumb data-pipe**. You read its machine-readable output, reason about it, and **propose** changes — you never let YOLO act on its own, and **YOLO never calls Claude**. You are the only agent in this loop.

## The one rule that matters

**Read freely. Never mutate AWS without explicit human approval.**

- **Safe to run unprompted** (read-only, no AWS writes): the whole `status:*` read family (`status`, `status:environment`, `status:logs`, `status:events`, `status:alarms`, `status:budget`), `audit --json`, `services --json`, `sync <env> --check`. All take `--json`.
- **Mutations — never run these yourself.** `sync` (apply), `deploy`, `rollback`, `scale`, `env:push`, `environment:*:push`, `services --add/--remove/--set`, `permissions` (RBAC grant-group edits), and the **teardown commands** (`destroy`, `destroy:app`, `destroy:environment` — irreversible; see [Teardown](#teardown)). Prepare them, explain them, and hand them to the human to run — or land the change as a **PR** (edit `yolo.yml`, open the PR) and let a human merge and deploy.
- Even with approval, honour YOLO's own confirm gates — don't reach for `--force`/`-f` unless the human explicitly asked for an unattended apply.

This mirrors the operator's standing rule: infrastructure commands never run against a real account without explicit confirmation.

## Prerequisites

- `codinglabsau/yolo` installed (`vendor/bin/yolo` exists) and a `yolo.yml` manifest in the project root.
- AWS auth resolves outside CI from `YOLO_<ENV>_AWS_PROFILE` in `.env`; YOLO STS-verifies the profile matches the manifest `account-id` before any call. If a command errors on auth, surface it — don't try to "fix" credentials.
- `<env>` is a key under `environments` in `yolo.yml` (usually `production` / `staging`). Read the manifest first if you don't know the env names.

## The data surface

These are your eyes. All exit non-zero on a problem, so they're scriptable.

### `yolo status <env> --json`

Live ECS / Auto Scaling / CloudWatch state. **Exits non-zero if a deployment is currently failed.**

```json
{
  "app": "myapp",
  "environment": "production",
  "groups": [
    {
      "group": "web",
      "exists": true,
      "tasks": { "running": 3, "desired": 3, "pending": 0 },
      "spec": { "cpu": "512", "memory": "1024", "launch": "FARGATE" },
      "revision": "web:42",
      "version": "26.24.1.0930",
      "rollout": { "state": "COMPLETED", "reason": null },
      "scaling": { "min": 1, "max": 4, "policies": ["cpu 65%", "req 1200"] },
      "cpuTarget": 65,
      "load": {
        "cpu": 18.4, "memory": 41.2, "requests": 7.1, "response": 0.12,
        "series": { "cpu": [12.1, 15.4, 18.4], "memory": [40, 41, 41.2], "requests": [5, 6, 7.1], "response": [0.11, 0.12, 0.12] }
      }
    }
  ],
  "queues": [{ "label": "queue", "name": "yolo-production-myapp", "backlog": 0 }]
}
```

One object per service group (`web` / `queue` / `scheduler`). Health signals: `tasks.running` vs `tasks.desired`, `rollout.state`, `load` against `cpuTarget`, and `queues[].backlog`. Each `load` metric carries both the latest reading **and** a `series` (oldest → newest) so you can see the *trend*, not just a lone number — rising CPU/requests with a flat task count is the headroom story. `queues` is app-level (it shows even when the queue worker is bundled into web).

### `yolo audit <env> --json`

Ownership/inventory check (not a config check). Flags anything tagged into the env's namespace that YOLO can't account for.

```json
{
  "environment": "production",
  "liveApps": ["myapp", "otherapp"],
  "okCount": 23,
  "unexpectedCount": 1,
  "resources": [
    {
      "scope": "app",
      "status": "unexpected",
      "type": "AWS::S3::Bucket",
      "name": "stray-bucket",
      "app": null,
      "reason": "no ownership tag",
      "arn": "arn:aws:s3:::stray-bucket"
    }
  ]
}
```

`status` is `ok` or `unexpected`; `reason` on an unexpected row is one of `no ownership tag`, `service no longer provisioned`, `app cluster gone`. `--unexpected` narrows to just the rows needing attention. Scope-narrow with `audit:environment <env>` / `audit:app <env> <app>`.

### `yolo sync <env> --check`

Read-only plan pass. Prints the full diff (Will create / Pending changes / Skipping) and **exits non-zero when infra has drifted** from the manifest (zero when in sync). This is the drift detector — parse the printed plan, trust the exit code.

### `yolo services <env> --json`

The service-lifecycle gate as data: which env-shared services (IVS, Typesense, …) are offered and which apps claim them.

### `yolo status:environment <env> --json`

The **env roll-up** — one compact row per live app in the environment (`{environment, apps: [{app, exists, tasks, revision, version, rollout}]}`). Your starting point for "how's the whole environment?" before drilling into a single app with `status`. Exits non-zero if any app has a failed deploy.

### Incident reads — `status:logs` / `status:events` / `status:alarms <env> --json`

When something looks wrong, these are the diagnosis surface (all app-tier, all `--json`):

- **`status:logs`** → `{groups: [{group, events: [{timestamp, message}]}]}` — recent CloudWatch logs per group.
- **`status:events`** → `{groups: [{group, events: [{createdAt, message}]}]}` — ECS service events (capacity / health-check / placement narrative).
- **`status:alarms`** → `{alarms: [{name, state, reason}]}` — CloudWatch alarm state; **exits non-zero when any alarm is in `ALARM`**.

### `yolo status:budget <env> --json`

Cost: `{currency, spend, budget: {amount, strategy}}` — month-to-date spend (USD) vs the declared cap. `spend` is `null` until the `yolo:app` cost-allocation tag is activated in Billing (Cost Explorer lags ~24h) — treat null as "not wired yet", not "zero spend". `budget.strategy` (`lean`/`balanced`/`conservative`) is how hard to trade cost against headroom — weight your recommendations by it.

## Workflows

**Bare `/yolo` — full sweep.** Read the manifest for env names. Start with `status:environment --json` for the env-wide picture, then `status --json` per app of interest, `audit --json` and `sync --check` for inventory + drift, and `status:budget --json` for cost. If anything's red, pull the incident reads (`status:alarms`, then `status:logs` / `status:events`). Report a tight health summary: per-group task health, any in-flight or failed rollout, autoscaling headroom (load trend vs `cpuTarget`), queue backlog, unexpected resources, drift, firing alarms, and spend vs budget. End with **green / needs-attention** and a short list of any proposals. Change nothing.

**`/yolo <question>` — specific ask.** Pull only the data the question needs (e.g. "is prod scaling ok?" → `status --json`, look at `scaling`/`load`/`cpuTarget`). Answer from the data; cite the numbers.

**`/loop /yolo` — attended copilot.** Quiet when everything's green; speak up only when a signal crosses a line (failed rollout, drift appears, tasks stuck pending, load pinned above target). Stay read-only.

**Proposing a change.** When the data warrants a change (scale bounds, a manifest fix, drift to reconcile): describe *what* and *why* with the numbers behind it, then either (a) hand the human the exact `yolo` command to run, or (b) edit `yolo.yml` and open a PR. One change per PR. Never run the mutation yourself.

## Teardown

The reverse of `sync` — same scope-first model, same plan → confirm → apply runner, run in **reverse** dependency order. The most destructive thing YOLO does and **irreversible**: never run a teardown yourself. Prepare it, explain exactly what it removes and what it keeps, and hand it to a human.

- **`destroy <env>`** — the full orchestrator: tears an app **and its environment** down in one pass (app → environment → account), the reverse of `sync`. Each scope self-gates: the network shell is reclaimed unless a database is attached, and the account-shared OIDC provider only when no other environment remains. Refuses while any *other* app still claims the env (this one is torn down in the same run).
- **`destroy:app <env>`** — tears the manifest's app down (Fargate, storage, app IAM, CDN, DNS) and reverses the per-app slice of any service it consumes — revokes its 3306/cache/Typesense ingress rules, deletes its per-app MediaConvert role, removes its per-app env file (`env/.env.{app}`, which also held minted Typesense keys). The env-shared resources (`:443` listener, cache, search cluster, WAF) stay for other apps. Refuses the shapes whose teardown isn't modelled yet — **multi-tenant**, **headless** (no domain), **no web task**.
- **`destroy:environment <env>`** — the mirror of `sync:environment`. Tears down **Tier A** (env-backed services, WAF, ALB + `:80`/`:443` listeners, Valkey cache, SNS, shared exec role, observer/admin IAM, env buckets) **then Tier B** (the network shell: VPC, subnets, route table, IGW, RDS SG + subnet group). The network is reclaimed automatically — *unless a database is attached to the VPC*, which keeps the whole shell standing (a live RDS pins it) and is named in the summary. Refuses while any app still claims the env — `destroy:app` each first.

**What it never touches, and the gates:**

- **RDS / the database is never deleted** — YOLO owns the security group, not the instance. It can't be: there is no destructive RDS call anywhere in YOLO (CI-enforced).
- The **BYO app data bucket stays** (holds user data) — it isn't even a deletable resource. The regeneratable env config/logs buckets are deleted as part of the teardown.
- **Shared infrastructure is reclaimed only when safe** — the network shell stays while a database is attached to the VPC; the account-shared GitHub OIDC provider is kept unless no other environment remains (and kept on *any* uncertainty — never deleted on a guess). Each is named in the end-of-run summary when kept.
- **The confirm gate is loud** — a red banner, a PROTECTED callout naming the database + app data bucket, and a type-the-environment-name prompt (no y/N). `--force` skips it for CI.

**Previewing is safe.** `--check` runs the plan pass read-only (prints the full teardown plan, writes nothing) — the way to show a human exactly what a teardown would remove before they run the apply. Only reach for it when teardown is the actual question, not during a routine sweep.

## Reasoning notes

- **Scaling.** `scaling.policies` shows the active target-tracking policies; `load.cpu` against `cpuTarget` is the headroom read. Bounds live in the manifest (`tasks.web.autoscaling.min/max`), so a scaling proposal is a manifest edit, applied by the next sync — not a raw `scale` call, unless it's a deliberate out-of-band nudge.
- **Drift vs inventory.** `sync --check` catches *config* drift (attributes vs manifest). `audit` catches *ownership* surprises (untagged or orphaned resources). They answer different questions — run both for a real sweep.
- **A failed deploy** shows as `rollout.state` failed and a non-zero `status` exit. YOLO's circuit breaker auto-rolls-back a broken rollout, so a failed state is usually already reverting — confirm with a second read before proposing anything. For the *why*, read `status:events` (placement/health-check messages) and `status:logs`.
- **Trends beat snapshots.** Use `load.series` to tell "climbing into a spike" from "settling after one" before proposing a scale change. A lone high reading isn't a trend.
- **Cost is advisory, not enforced.** `status:budget` reports spend vs cap; YOLO never acts on it. A `null` spend means the cost-allocation tag isn't active yet (not zero). Weight scale/cost proposals by `budget.strategy`.

## Full reference

The complete command and manifest reference ships as a Boost guideline (`resources/boost/guidelines/yolo.blade.php`) and, for humans, at the [docs site](https://github.com/codinglabsau/yolo). When in doubt about a flag, `vendor/bin/yolo <command> --help`.
