# Scaling

YOLO runs the web service as a single Fargate task by default. You can scale it two ways:

- **Autoscaling** — let AWS adjust the task count automatically from live metrics.
- **`yolo scale`** — set the capacity yourself, out of band, without a deploy.

Both leave capacity **create-only**: a `sync` or `deploy` never resets the task count, so neither the autoscaler nor a manual scale is ever clobbered by a routine deploy.

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

[`yolo scale`](/reference/commands#yolo-scale) changes capacity without a build or deploy. Like `env:push`, it shows a current → new comparison and asks before applying:

```bash
yolo scale production 3      # set the count (prompts if omitted)
```

It's autoscaling-aware. With no scalable target it sets the ECS desired count directly. Once autoscaling is on, a raw desired count would just be overridden on the next evaluation — so `scale` raises the target's **minimum capacity** (the floor) instead, which is the durable lever.

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
