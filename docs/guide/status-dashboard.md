# Status Dashboard

`yolo status <env>` opens a tabbed, **read-only** terminal dashboard over an environment — live vitals, logs, deployments and the service gate in one place, polled and redrawn until you quit. It's a window, not a cockpit: there are no actions, only navigation. Every change you might want — deploying, rolling back, managing a service — is its own command, run as an interactive [Laravel Prompts](https://laravel.com/docs/prompts) flow so the atomic task gets your full attention.

```bash
yolo status production              # live dashboard
yolo status production --snapshot   # one frame, then exit
yolo status production --json       # structured payload for scripts
```

In a real terminal the dashboard is the default. `--snapshot` (and any non-interactive shell — a pipe, CI) renders a single [`status`](/reference/commands#yolo-status) frame instead, and `--json` emits the machine-readable payload.

The frame is fitted to the terminal: the global bar and tabs sit up top, the footer stays pinned to the bottom row, and the active tab's body fills the space between. Tall content (logs, a long deploy history) clips to that space and scrolls rather than overflowing.

## The tabs

| Tab | What it shows |
|---|---|
| **Overview** | Per-group vitals, load, scaling, queue backlogs and any in-flight rollout — the same picture [`status --snapshot`](/reference/commands#yolo-status) renders — plus an app-wide CloudWatch alarms summary: the alarm count, and a row for each firing one. The full inventory (OK states and all) is one command away in [`status:alarms`](/reference/commands#yolo-status-alarms) |
| **Web** · **Queue** · **Scheduler** | One tab per group the app actually runs — a combined app shows only **Web**. Each gathers everything about that group in one place: its vitals (task counts, spec, scaling, live load), CPU / memory braille charts over the last hour (and request rate / response time for the web group), and a tail of its recent CloudWatch logs. The readable replacement for the old standalone Metrics / Logs tabs; for a longer window, the [CloudWatch dashboard](/reference/commands#yolo-status) |
| **Deployments** | Recent deployments from ECR, the running version marked; live progress while a rollout is in flight |
| **Database** | The RDS instance or Aurora cluster the manifest [`database:`](/reference/manifest#database) key declares — CPU, connections, freeable memory and latency over the last hour. YOLO reads the name from the manifest (never the app's secret `.env`); the tab is empty until one is declared |
| **Cache** | The shared Valkey cache — status, endpoint and engine CPU / memory / connections / evictions. Empty when the environment runs no cache |
| **Services** | The [service lifecycle](/guide/services#the-service-lifecycle): what the environment declares, which apps claim it, and its lifecycle state — plus the Typesense cluster's live CPU / memory when it's offered |

A **global health bar** stays pinned at the top on every tab — one dot per group (web / queue / scheduler), green when healthy, red when down. When a deploy is in flight it flips to a rollout banner, **whoever triggered it** — your `yolo deploy` in another shell, CI, or a teammate's rollback. The dashboard reads that straight from ECS, so it's never out of step with what's actually rolling.

The tabs with a single primary resource — Database, Cache, Services — also carry a muted **AWS Console deep link** to it, so jumping to the full console view is one click away without cluttering the panel.

## Navigating

| Key | Does |
|---|---|
| <kbd>◂</kbd> <kbd>▸</kbd> / <kbd>Tab</kbd> | Previous / next tab |
| <kbd>1</kbd>…<kbd>8</kbd> | Jump to a tab by number |
| a tab's letter (`o` `d` `b` `c` `s`) | Jump straight to it. The per-group tabs (Web / Queue / Scheduler) have no letter — reach them by number or <kbd>◂</kbd> <kbd>▸</kbd> |
| <kbd>↑</kbd> <kbd>↓</kbd> / <kbd>PgUp</kbd> <kbd>PgDn</kbd> | Scroll the active tab's body (a group's charts + logs, deploy history) |
| <kbd>Home</kbd> / <kbd>End</kbd> | Jump to the top / bottom of the body |
| <kbd>q</kbd> | Quit |

A scrollable tab shows a `▲ / ▼ more` hint when there's content beyond the window.

## Why read-only

The dashboard used to embed actions — managing a service, rolling back, editing the domain — by handing the screen to a prompt mid-loop and resuming after. That made the live view and the action feel like two apps fighting over one terminal, and a change only surfaced on the next poll. So the dashboard is now purely a window: it shows you what's happening, and you act with the matching command — [`deploy`](/reference/commands#yolo-deploy), [`rollback`](/reference/commands#yolo-rollback), [`scale`](/reference/commands#yolo-scale), [`services`](/reference/commands#yolo-services) — each an interactive Prompts flow with its own guards. One thing on screen at a time, done well.
