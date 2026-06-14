# Interactive Dashboard

`yolo tui` opens a tabbed terminal UI over an environment — live status, the service gate, deployments and rollback, logs, and the manifest, all in one place. It adds no new powers: every action routes through the same commands you'd run by hand ([`status`](/reference/commands#yolo-status), [`services`](/reference/commands#yolo-services), [`rollback`](/reference/commands#yolo-rollback)). Think of it as the live cockpit on top of them.

```bash
yolo tui                # prompt for the environment
yolo tui production     # straight in
```

With no argument it prompts for the environment (auto-selecting the only one when a manifest declares just one). It's interactive only — for a single frame in a script, use [`yolo status --snapshot`](/reference/commands#yolo-status).

## The tabs

| Tab | What it shows | Actions |
|---|---|---|
| **Status** | Per-group vitals, load, scaling, and any in-flight rollout — the same view [`status`](/reference/commands#yolo-status) renders | — |
| **Services** | The two-key [service gate](/guide/provisioning#the-service-lifecycle): what's offered, which apps claim it, and its lifecycle state | <kbd>⏎</kbd> manage (add / edit / remove) |
| **Deployments** | Recent deployments from ECR, the running version marked; live progress while a rollout is in flight | <kbd>⏎</kbd> roll back |
| **Logs** | Recent CloudWatch logs for one service group | <kbd>g</kbd> cycle group |
| **Manifest** | A read view of the environment manifest and the app's `yolo.yml` | — |

A **global health bar** stays pinned at the top on every tab — one dot per group (web / queue / scheduler), green when healthy, red when down. When a deploy is in flight it flips to a rollout banner, **whoever triggered it** — your `yolo deploy` in another shell, CI, or the Deployments tab's own rollback. The dashboard reads that straight from ECS, so it's never out of step with what's actually rolling.

## Navigating

| Key | Does |
|---|---|
| <kbd>◂</kbd> <kbd>▸</kbd> / <kbd>Tab</kbd> | Previous / next tab |
| <kbd>1</kbd>…<kbd>5</kbd> | Jump to a tab by number |
| a tab's letter (`s` `v` `d` `l` `m`) | Jump straight to it |
| <kbd>q</kbd> | Quit |

When you trigger an action — managing a service, rolling back — the dashboard hands the screen to the prompt, you complete it, and the live view resumes.

## Editing safely

The dashboard never hides the destructive surface. Withdrawing a service offer is refused while a running app still claims it; a rollback warns that the database is not rolled back and defaults to *no*; both run the exact same guards as their standalone commands. Read-heavy tabs (Status, Logs, Manifest) make no changes at all.
