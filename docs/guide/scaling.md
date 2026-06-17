# Scaling

By default an app runs as **one Fargate task** doing everything — Octane plus the bundled queue worker and scheduler. That's the cheap floor and fine at low scale. The three workloads have different scaling shapes, though, so each can be **extracted into its own ECS service** that scales independently:

| Service | How it scales | Opt in with |
|---|---|---|
| **web** | target tracking (request concurrency + CPU), `min`→`max` | `tasks.web.autoscaling` |
| **queue** | backlog-per-task, **scales to zero** | top-level `tasks.queue` |
| **scheduler** | never — pinned singleton (exactly one task) | top-level `tasks.scheduler` |

Extraction is opt-in by presence — there are no `tasks.web.queue` / `tasks.web.scheduler` flags. Add a top-level `tasks.queue` block to peel the **worker tier** (the queue worker *and* the scheduler) out of web, leaving web as pure Octane; add `tasks.scheduler` as well to give cron its own pinned-singleton task (see [the scheduler](#the-scheduler)).

You can scale the web (and queue) service two ways:

- **Autoscaling** — let AWS adjust the task count automatically from live metrics.
- **`yolo scale`** — set the capacity yourself, out of band, without a deploy.

Autoscaling **bounds** (`min`/`max`) live in the manifest and are reconciled by sync, so they're declarative and never drift — with a guard so a stale manifest can't scale production down unattended (see [Reducing capacity is guarded](#reducing-capacity-is-guarded)). A **fixed** service's desired count is create-only — set once, then owned by `yolo scale`, never reset by a routine sync or deploy.

## Autoscaling

`yolo init` scaffolds new apps with web autoscaling **on** (`tasks.web.autoscaling: true`, bounds 1–4) — so a fresh app scales out of the box. To set your own bounds, expand the shorthand into a block:

```yaml
tasks:
  web:
    autoscaling:
      min: 1
      max: 6
      cpu-utilization: 65   # optional — the safety-net policy's target
```

The scaffolded shorthand takes the defaults (`min: 1`, `max: 4`):

```yaml
tasks:
  web:
    autoscaling: true       # shorthand for the defaults — on by default; `false` = a fixed single task
```

Autoscaling is **on by default** for any enabled web tier — an omitted `autoscaling` key behaves like `true`. On the next `yolo sync` / `yolo sync:app`, YOLO registers an [Application Auto Scaling](https://docs.aws.amazon.com/autoscaling/application/userguide/what-is-application-auto-scaling.html) **scalable target** on the ECS service (bounded by `min`/`max`) and attaches **target-tracking policies** to it. Set `autoscaling: false` to keep the service a fixed single task instead.

### Two metrics, composed

YOLO runs two target-tracking policies at once. Application Auto Scaling takes the **maximum** desired count any policy asks for, so they compose rather than fight — scale-out always wins.

| Policy | Metric | Role |
|---|---|---|
| **Request concurrency** | in-flight requests per task (derived) | The default, leading signal — concurrency climbs the instant traffic does, ahead of CPU. Scales the web tier under normal HTTP load. No tuning: its target comes from the task's memory. |
| **CPU** | `ECSServiceAverageCPUUtilization` | The safety net. Catches load that pegs the CPU without raising request concurrency — a few heavy, low-rate requests. Target defaults to 65%. |

Both are on the moment the web tier is enabled (autoscaling is on by default) — there's nothing to seed from a load test first. Scaling on the requests a task is actively serving rather than trailing CPU means faster responses need fewer tasks for the same traffic, and a spike is caught as it arrives.

### How the concurrency target is derived

The ALB doesn't publish in-flight concurrency, so YOLO derives it with CloudWatch metric math from two metrics it does — request rate and response time (Little's Law, `concurrency = rate × latency`):

```
concurrency_per_task = (RequestCountPerTarget / 60) × TargetResponseTime
```

and target-tracks it at a value derived from the task's memory — `floor(memory_mb / 30)` PHP workers per task (each ~30 MB, serving one request at a time) held at **70% utilisation**. A 1024 MB task → 34 workers → a target of ~23 concurrent requests, leaving headroom for the within-minute peak and the next task's cold start. Resize the task (`tasks.web.memory`) and the target follows; there's no separate knob.

Because the signal includes latency, a slow downstream dependency (a struggling database) raises concurrency and scales the web tier out even when more tasks won't help — the `max` bound is the backstop there, since CPU stays low when the stall is downstream.

### Faster scale-out: burst

The two policies above scale on ALB metrics, which are 1-minute resolution — a good signal, but ~1 min behind a sudden spike. So once you're autoscaling, YOLO also runs a **burst** path. There's no knob for it: it's near-free and fails safe, and no app wants slower scaling, so — like the concurrency and CPU policies — it's just part of how web autoscaling works, provisioned wherever the scalable target is. (The signal is FrankenPHP's worker metrics, which only worker mode — Octane, the default — populates; a classic-mode tier simply never emits it, so the alarm sits inert and burst is a no-op there. Nothing to switch on or off.)

Burst adds a **step-scaling policy** driven by a **high-resolution alarm** (10s) on a signal the container reports about *itself*: each web task publishes its FrankenPHP worker saturation (busy ÷ total workers) — an *earlier* indicator than the ALB, since workers fill before latency even climbs. Detection drops from ~60s to **~10–15s**; at ≥80% saturation it adds a task, at ≥90% it adds two. Scale-in stays with the target-tracking policies, so burst can only ever scale out faster, never fight them.

How it works, and what it costs:

- A tiny supervised process in the web container reads FrankenPHP's metrics endpoint and **publishes the saturation directly via `PutMetricData`** — but only while it's hot (≥70%), and it snoozes the cooldown after a tripping datapoint (one breach already steps the count out), so CloudWatch is touched only during a spike. Going direct lands the datapoint synchronously; an EMF log line instead rides the logs pipeline — and the ECS `awslogs` driver exposes no flush-interval knob (AWS recommends ≤5s for high-res EMF alarms) while extraction is async, so it surfaces on a cadence you don't control. The cost is one namespace-scoped `cloudwatch:PutMetricData` grant on the task role plus the `aws/aws-sdk-php` SDK — which ships in the image transitively via YOLO itself, a production dependency of every deployed app (a build preflight hard-fails if YOLO is only a dev dependency).
- To turn that endpoint on, YOLO runs Octane against a Caddyfile it generates — your installed Octane stub with the top-level `metrics` global option added, passed via `--caddyfile`. It's generated only for an autoscaling Octane web tier and is your stub untouched bar that one line (so Octane still fills its own placeholders). An env var won't do here: `octane:start` rebuilds `CADDY_GLOBAL_OPTIONS` itself, discarding any value set on the task. The endpoint binds container-loopback (`localhost:2019`) only — never the load-balanced port — so it adds no external surface.
- Cost is the one high-resolution alarm (**$0.30/app/month**) plus the custom metric (another **~$0.30**, but only in months the service actually bursts — a metric is billed only when it receives data). The puts themselves are effectively free: inside CloudWatch's 1M-request/month API free tier, and $0.01 per *million* beyond it. Same metric + alarm cost as the old EMF path — switching transport doesn't change the bill.

::: warning Burst is not a substitute for warm capacity
Even instant detection still waits ~55s for the new task to boot and pass ALB health. So reactive scaling — burst included — bottoms out at ~1 min to relief; below that you need a task that's already running (`min ≥ N`). Burst makes the spike that *exceeds* your warm headroom land faster; it doesn't remove the need for the headroom.
:::

The burst alarm and step policy aren't taggable, so (like the target-tracking policies) they don't appear in [`yolo audit`](/reference/commands#yolo-audit); setting `autoscaling: false` (or switching the web tier to classic mode) deletes both on the next sync.

### Turning autoscaling off

Autoscaling is declarative — sync reconciles live state down to what the manifest asks for, so removing the `autoscaling` block deregisters the scalable target on the next `yolo sync`, which cascades the delete to **every** policy and alarm on it.

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

For a fixed service (`autoscaling: false` — web or queue) a positional `count` sets the ECS desired count directly. An autoscaling service (the default for web and queue) only takes `--min`/`--max`. The scheduler is a singleton and can't be scaled (`--scheduler` errors out).

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
  web: true
  queue:
    autoscaling:
      min: 0          # scale to zero when idle (opt-in; the default floor is 1)
      max: 20
      backlog-per-task: 100
    spot: true        # optional: ~70% cheaper interruptible capacity
```

Like web, the queue **autoscales by default** (`min: 1`, `max: 10`) — set `autoscaling: false` for a fixed single task. It scales on **backlog per task** — `ApproximateNumberOfMessagesVisible / RunningTaskCount`, computed with CloudWatch metric math (no Lambda) and held at `backlog-per-task` messages per running task. As the backlog grows it scales out toward `max`; as it drains it scales back in toward `min`.

With `autoscaling.min: 0` the queue **scales to zero**: no tasks and no compute cost when idle. Target tracking can't lift it off zero (dividing by zero running tasks is undefined), so YOLO also attaches a step-scaling alarm that sets the service to exactly one task the instant a message becomes visible; target tracking owns it from one upward. The cost is a **~30–60s cold start** (image pull + boot) on the first message after idle.

That makes the choice of *where* the queue lives a latency decision:

| Topology | Idle cost | Pickup latency | Use for |
|---|---|---|---|
| Bundled (no `tasks.queue` block) | included in web | **instant** (worker always warm) | light, latency-sensitive jobs |
| Standalone, `min: 0` | **~$0** | ~30–60s cold start from idle | bursty, latency-tolerant async |
| Standalone, `min: 1+` | one always-on task | instant, then autoscales | high-volume, always-busy |

For multi-tenant apps, a single queue service works the app's default queue; per-tenant queue fan-out is on the roadmap and isn't covered here.

## The scheduler

The scheduler ([supercronic](https://github.com/aptible/supercronic) firing `schedule:run` every minute) must run as a **singleton** — if it runs on N tasks, every scheduled job fires N times (N× emails, N× billing, N× reports). The queue is safe to multiply (SQS hands each message to one worker); the scheduler is not. There's no stable per-task identity on Fargate to elect one from, so pick one of two strategies.

### 1. `->onOneServer()`

Keep the scheduler bundled in its default container (the web container, or the standalone queue if you've extracted one) and add Laravel's [`onOneServer()`](https://laravel.com/docs/scheduling#running-tasks-on-one-server) to **every** scheduled task. It takes an atomic lock in the shared cache so only one replica runs each task per minute:

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
When the scheduler is bundled into a host that runs more than one task — an autoscaling web task, or a standalone queue (both autoscale by default) — `yolo sync` lists an advisory under the plan's **Warnings** section pointing at these two strategies. It's a nudge, not a gate — YOLO can't see inside your kernel to know whether you've used `onOneServer()`.
:::
