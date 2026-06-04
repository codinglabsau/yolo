# Scaling

By default an app runs as **one Fargate task** doing everything — Octane plus, if enabled, the bundled queue worker and scheduler (`tasks.web.queue` / `tasks.web.scheduler`). That's the cheap floor and fine at low scale. The three workloads have different scaling shapes, though, so each can be **extracted into its own ECS service** that scales independently:

| Service | How it scales | Opt in with |
|---|---|---|
| **web** | target tracking (CPU + request count), `min`→`max` | `tasks.web.autoscaling` |
| **queue** | backlog-per-task, **scales to zero** | top-level `tasks.queue` |
| **scheduler** | never — pinned singleton (exactly one task) | top-level `tasks.scheduler` |

Extraction is additive and per-workload: keep the queue bundled but give the scheduler its own service, or any mix. A workload can't be both bundled (`tasks.web.queue`) and extracted (`tasks.queue`) at once — `sync` hard-fails if you configure both.

You can scale the web (and queue) service two ways:

- **Autoscaling** — let AWS adjust the task count automatically from live metrics.
- **`yolo scale`** — set the capacity yourself, out of band, without a deploy.

Autoscaling **bounds** (`min`/`max`) live in the manifest and are reconciled by sync, so they're declarative and never drift — with a guard so a stale manifest can't scale production down unattended (see [Reducing capacity is guarded](#reducing-capacity-is-guarded)). A **fixed** service's desired count is create-only — set once, then owned by `yolo scale`, never reset by a routine sync or deploy.

## Autoscaling

Add a `tasks.web.autoscaling` block to the environment to turn it on:

```yaml
tasks:
  web:
    queue: true
    scheduler: true
    autoscaling:
      min: 1
      max: 6
      cpu-utilization: 65
      request-count-per-target: 1000   # seed from a load test
```

On the next `yolo sync` / `yolo sync:app`, YOLO registers an [Application Auto Scaling](https://docs.aws.amazon.com/autoscaling/application/userguide/what-is-application-auto-scaling.html) **scalable target** on the ECS service (bounded by `min`/`max`) and attaches **target-tracking policies** to it. Without the block, the service stays at a fixed single task.

### Two metrics, composed

YOLO can run two target-tracking policies at once. Application Auto Scaling takes the **maximum** desired count any policy asks for, so they compose rather than fight — scale-out always wins.

| Policy | Metric | Role |
|---|---|---|
| **CPU** | `ECSServiceAverageCPUUtilization` | Always on with autoscaling. Works with no tuning — a sane default catches load that saturates the CPU (including a few heavy, low-rate requests). |
| **Request count** | `ALBRequestCountPerTarget` | Added only once `request-count-per-target` is set. The *leading* indicator — per-target request rate climbs the instant traffic does, ahead of CPU and latency. |

Start with CPU (it ships working). Add the request-count policy once you have a number for it.

### Choosing the request-count target

`request-count-per-target` is **requests per task per minute** — the point at which one task is comfortably busy but not degrading. Don't guess it: run a load test, watch p95 response time as you ramp concurrency, and take the per-target request rate at the plateau just before p95 starts climbing. Seed that number, then tune `scale-out-cooldown` / `scale-in-cooldown` if the service oscillates.

Until you set it, CPU-based autoscaling is already active — you lose nothing by waiting for real data.

### Turning autoscaling off

Autoscaling is declarative — sync reconciles live state down to what the manifest asks for, so removing config tears the matching infrastructure back down on the next `yolo sync`:

| You remove… | Next sync… |
| --- | --- |
| `request-count-per-target` (keep the block) | Deletes the request-count policy and the scale-out / scale-in alarms AWS generated for it. The CPU policy stays. |
| The whole `autoscaling` block | Deregisters the scalable target, which cascades the delete to **every** policy and alarm on it. |

Deregistering doesn't drop tasks — the service reverts to a **fixed** task count frozen at its current live count. Bring it down with [`yolo scale`](#manual-scaling) if you no longer need the extra capacity.

### What isn't tagged

Application Auto Scaling targets and policies can't carry tags, so they don't show up in [`yolo audit`](/reference/commands#yolo-audit) — they're reconciled by config (above) rather than by the tag-driven audit.

## Manual scaling

[`yolo scale`](/reference/commands#yolo-scale) changes capacity without a build or deploy. Like `env:push`, it shows a current → new comparison and asks before applying.

```bash
yolo scale production --web --min=3 --max=10     # web autoscaled: set the bounds
yolo scale production --web 3                      # web fixed: set the desired count
yolo scale production --queue --min=0 --max=20     # queue bounds (min 0 = scale to zero)
```

Under autoscaling you set the **bounds** (`--min`/`--max`), never a desired count — the policies own desired count and would override it. Crucially, `scale` **writes the bounds back to the manifest** (surgically — your comments and formatting survive), so the manifest stays the single source of truth and the next `yolo sync` reconciles to the same values rather than clobbering your change.

For a fixed web service (no `autoscaling` block) a positional `count` sets the ECS desired count directly. A standalone queue is always autoscaling-managed, so it only takes `--min`/`--max`. The scheduler is a singleton and can't be scaled (`--scheduler` errors out).

### Reducing capacity is guarded

Because the manifest is authoritative, a `yolo sync` run with a **stale** manifest could otherwise scale production *down* — exactly the wrong thing during an incident. So lowering a live bound is gated:

- **`yolo scale`** down → an explicit confirm that defaults to **no**.
- **`yolo sync`** (interactive) → the reduction shows in the plan and the normal confirm gate guards it; abort and nothing changes.
- **`yolo sync --force` / non-interactive** → the reduction is **refused** (skipped + warned). Lowering capacity must be deliberate and attended — an interactive sync or `yolo scale`. Raises always apply.

So an emergency `yolo scale production --web --min=10` is durable: it's written to the manifest *and* live, and no unattended sync can quietly walk it back.

## The queue (scale to zero)

Add a top-level `tasks.queue` block to give the queue worker its own ECS service, separate from web:

```yaml
tasks:
  web: {}
  queue:
    min: 0          # scale to zero when idle (the default)
    max: 20
    backlog-per-task: 100
    spot: true      # optional: ~70% cheaper interruptible capacity
```

It scales on **backlog per task** — `ApproximateNumberOfMessagesVisible / RunningTaskCount`, computed with CloudWatch metric math (no Lambda) and held at `backlog-per-task` messages per running task. As the backlog grows it scales out toward `max`; as it drains it scales back in toward `min`.

With `min: 0` the queue **scales to zero**: no tasks and no compute cost when idle. Target tracking can't lift it off zero (dividing by zero running tasks is undefined), so YOLO also attaches a step-scaling alarm that sets the service to exactly one task the instant a message becomes visible; target tracking owns it from one upward. The cost is a **~30–60s cold start** (image pull + boot) on the first message after idle.

That makes the choice of *where* the queue lives a latency decision:

| Topology | Idle cost | Pickup latency | Use for |
|---|---|---|---|
| Bundled (`tasks.web.queue: true`) | included in web | **instant** (worker always warm) | light, latency-sensitive jobs |
| Standalone, `min: 0` | **~$0** | ~30–60s cold start from idle | bursty, latency-tolerant async |
| Standalone, `min: 1+` | one always-on task | instant, then autoscales | high-volume, always-busy |

For multi-tenant apps, a single queue service works the app's default queue; per-tenant queue fan-out composes with [LPX-601](https://linear.app/codinglabsau/issue/LPX-601) and isn't covered here.

## The scheduler

The scheduler (`crond` firing `schedule:run` every minute) must run as a **singleton** — if it runs on N tasks, every scheduled job fires N times (N× emails, N× billing, N× reports). The queue is safe to multiply (SQS hands each message to one worker); the scheduler is not. There's no stable per-task identity on Fargate to elect one from, so pick one of two strategies.

### 1. `->onOneServer()`

Keep the scheduler bundled in the web container (`tasks.web.scheduler: true`) and add Laravel's [`onOneServer()`](https://laravel.com/docs/scheduling#running-tasks-on-one-server) to **every** scheduled task. It takes an atomic lock in the shared cache so only one replica runs each task per minute:

```php
$schedule->command('reports:send')->daily()->onOneServer();
```

This requires a shared lock store (the Valkey/Redis cache YOLO provisions, or a database cache) — which production apps run anyway. It keeps the simple single-service topology and lets the bundled task scale freely.

The catch: it's per-task. A scheduled task registered by a package (Telescope pruning, backups, etc.) that you can't annotate will still multi-fire — which is your signal to reach for strategy 2.

### 2. Extract the scheduler (recommended once web scales)

Give the scheduler its own service with a top-level `tasks.scheduler` block:

```yaml
tasks:
  web:
    autoscaling: { min: 1, max: 6 }
  scheduler: {}     # its own pinned-singleton service
```

YOLO pins it at exactly one task (never a scalable target) and deploys it **stop-then-start** (`minimumHealthyPercent: 0` / `maximumPercent: 100`) so a rollout stops the old cron before starting the new one — a deploy never briefly runs two schedulers (a missed cron minute is harmless; a double-run isn't). This removes the `onOneServer()` *requirement* entirely — it's genuinely a singleton now — though leaving `onOneServer()` on is harmless. The web tier then scales without any scheduler concern.

::: tip
When you enable autoscaling on a web task that still **bundles** the scheduler, `yolo sync` prints a one-line advisory pointing at these two strategies. It's a nudge, not a gate — YOLO can't see inside your kernel to know whether you've used `onOneServer()`.
:::
