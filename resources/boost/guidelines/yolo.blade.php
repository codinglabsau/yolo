## YOLO deployment (codinglabsau/yolo)

This app is deployed to **AWS Fargate (ECS)** with YOLO (`vendor/bin/yolo`). YOLO provisions and reconciles the app's AWS resources (VPC/subnets, ALB, ECR, ECS cluster/service/task defs, S3, IAM, Route 53, ACM, SQS, CloudWatch) and runs zero-downtime container deploys. Config lives in `yolo.yml`; each command takes an `<env>` argument naming a key under `environments`.

### Safety — never run infrastructure mutations unprompted

Treat these as **human-gated**: `deploy`, `rollback`, `scale`, `sync` (apply), `env:push`, `environment:*:push`, `services --add/--remove/--set`. They hit a real AWS account. Prepare and explain them; let a human run them (or land the change as a PR). Do **not** pass `--force`/`-f` unless explicitly asked for an unattended apply.

These are **read-only and safe** to run when you need live state: `status <env> --json`, `audit <env> --json`, `services <env> --json`, `sync <env> --check`.

### Machine-readable state (the data-pipe)

- `yolo status <env> --json` → `{app, environment, groups[]}`; each group (`web`/`queue`/`scheduler`) carries `tasks{running,desired,pending}`, `spec{cpu,memory,launch}`, `revision`, `version`, `rollout{state,reason}`, `scaling`, `cpuTarget`, `load`. **Exits non-zero if a deploy is currently failed.**
- `yolo audit <env> --json` → `{environment, liveApps[], okCount, unexpectedCount, resources[]}`; each resource has `{scope, status, type, name, app, reason, arn}` where `status` is `ok`/`unexpected`. Ownership/inventory check, not a config check.
- `yolo sync <env> --check` → read-only plan; **non-zero exit on drift** from the manifest. The CI drift gate.
- `yolo services <env> --json` → which env-shared services are offered and which apps claim them.

### Command surface

| Command | Purpose |
|---|---|
| `init` | Scaffold `yolo.yml`, Dockerfile, supporting files (only command that runs without a manifest) |
| `build <env>` | Build + push the container image to ECR |
| `deploy <env>` | Build, then zero-downtime rollout (circuit breaker auto-rolls-back on failure) |
| `rollback <env>` | Re-deploy a prior ECR version, no build (DB is **not** reverted) |
| `status <env>` | Live dashboard (`--snapshot` one frame, `--json` machine-readable) |
| `run <env>` | Shell / one-off command in a running container (ECS Exec) |
| `scale <env> [count]` | Adjust capacity out of band (`--web`/`--queue`, `--min`/`--max`) |
| `services <env>` | View/manage env-shared services |
| `tui [env]` | Interactive dashboard |
| `sync[:account\|:environment\|:app] <env>` | Provision/reconcile resources (`--check` plan-only, `--force` skip confirm) |
| `audit[:environment\|:app] <env>` | Flag resources YOLO can't account for (`--unexpected`, `--json`) |
| `env:pull\|env:push <env>` | Download/upload the app's `.env` from S3 (push shows a diff, then offers to delete the local copy) |

`sync` is always approve-before-apply: it prints the full plan (Will create / Pending changes / Skipping) and confirms before writing — declining is the preview. `--check` is the non-interactive plan-only/fail-on-drift form for CI. There is **no `--dry-run`** (that was the retired EC2-era YOLO).

### Manifest (`yolo.yml`) essentials

- Required keys per environment: `name`, `region`, `account-id`. Topology is encoded by what's declared.
- `tasks.web` / `tasks.queue` / `tasks.scheduler` — declaring a task provisions its ECS service; bundling vs standalone is derived from presence. `tasks.web.autoscaling` (with `min`/`max`) turns on web target-tracking autoscaling (on by default from `init`, bounds 1–4).
- Multi-tenancy is a manifest concern (`tenants`), fanned out at the step level — there is no `sync:tenant`/`deploy:tenant` verb; narrow with `--tenant=<id>`.
- This is the **Fargate** YOLO. There is no EC2/ASG/CodeDeploy/AMI/`image:create`/`stage`/instance-type — if you see those referenced anywhere, it's stale alpha-era material.

When unsure of a flag, run `vendor/bin/yolo <command> --help`.
