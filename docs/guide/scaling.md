# Scaling

YOLO runs the web service as a single Fargate task by default. You can scale it two ways:

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

### What isn't tagged

Application Auto Scaling targets and policies can't carry tags, so they don't show up in [`yolo audit`](/reference/commands#yolo-audit). Tearing an app down has to deregister the scalable target explicitly.

## Manual scaling

[`yolo scale`](/reference/commands#yolo-scale) changes capacity without a build or deploy. Like `env:push`, it shows a current → new comparison and asks before applying.

```bash
yolo scale production --web --min=3 --max=10   # autoscaled: set the bounds
yolo scale production --web 3                    # fixed: set the desired count
```

Under autoscaling you set the **bounds** (`--min`/`--max`), never a desired count — the policies own desired count and would override it. Crucially, `scale` **writes the bounds back to the manifest** (surgically — your comments and formatting survive), so the manifest stays the single source of truth and the next `yolo sync` reconciles to the same values rather than clobbering your change.

For a fixed service (no `autoscaling` block) a positional `count` sets the ECS desired count directly.

### Reducing capacity is guarded

Because the manifest is authoritative, a `yolo sync` run with a **stale** manifest could otherwise scale production *down* — exactly the wrong thing during an incident. So lowering a live bound is gated:

- **`yolo scale`** down → an explicit confirm that defaults to **no**.
- **`yolo sync`** (interactive) → the reduction shows in the plan and the normal confirm gate guards it; abort and nothing changes.
- **`yolo sync --force` / non-interactive** → the reduction is **refused** (skipped + warned). Lowering capacity must be deliberate and attended — an interactive sync or `yolo scale`. Raises always apply.

So an emergency `yolo scale production --web --min=10` is durable: it's written to the manifest *and* live, and no unattended sync can quietly walk it back.

## The scheduler caveat

This is the one thing to get right before scaling a service that runs the scheduler.

In the default topology the web container also runs the queue worker and the **scheduler** (`crond` firing `schedule:run` every minute). The queue is safe to multiply — SQS only hands each message to one worker. The scheduler is **not**: scale to N tasks and `schedule:run` fires on every replica, so every scheduled task runs N times (N× emails, N× billing, N× reports).

There's no stable per-task identity on Fargate to elect a single scheduler from, so pick one of two strategies:

### 1. `->onOneServer()` (recommended)

Add Laravel's [`onOneServer()`](https://laravel.com/docs/scheduling#running-tasks-on-one-server) to **every** scheduled task in your console kernel. It takes an atomic lock in the shared cache so only one replica runs each task per minute:

```php
$schedule->command('reports:send')->daily()->onOneServer();
```

This requires a shared lock store (Redis, DynamoDB, or a database cache) — which production apps run anyway. It keeps the simple single-service topology and lets the bundled task scale freely.

The catch: it's per-task. A scheduled task registered by a package (Telescope pruning, backups, etc.) that you can't annotate will still multi-fire — which is your signal to reach for strategy 2.

### 2. Separate the scheduler

Move the scheduler into its own service pinned at exactly one task. This removes the requirement entirely (it's genuinely a singleton) and lets the web tier scale without any scheduler concern. Tracked in [LPX-649](https://linear.app/codinglabsau/issue/LPX-649).

::: tip
When you enable autoscaling on a task that still runs the scheduler, `yolo sync` prints a one-line reminder of exactly this. It's a nudge, not a gate — YOLO can't see inside your kernel to know which strategy you chose.
:::
