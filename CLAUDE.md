# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

YOLO is a PHP CLI tool for deploying Laravel applications to **AWS Fargate (ECS)**. It provisions and manages the AWS
resources an app needs — VPC/subnets, an Application Load Balancer, ECR, the ECS cluster/service/task definitions, S3,
IAM roles, Route 53, ACM certificates, SQS, and CloudWatch/IVS logging — and handles zero-downtime container
deployments.

> The earlier EC2/ASG/CodeDeploy generation of YOLO lives on as the separate `codinglabsau/yolo-alpha` package
> (`Codinglabs\YoloAlpha`). This codebase is the Fargate rewrite — there is no EC2, autoscaling group, CodeDeploy, or
> on-instance `start` command here.

## Rules

- Always format code with pint after making changes
- Always run tests before pushing changes
- **Update the docs when you change behaviour or the public surface.** Any change to a command's
  arguments/options, a manifest key (name, default, or semantics), the Dockerfile/entrypoint contract, the
  manifest-required keys, or the sync/audit scope model must update the matching page under `docs/` in the same
  change — the CLI reference (`docs/reference/commands.md`), the manifest reference
  (`docs/reference/manifest.md`), and any affected guide page (`docs/guide/*`). Treat the docs as part of the
  change, not a follow-up. The VitePress site fails its build on dead internal links, so keep cross-links valid.

## Commands

```bash
# Run tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Arch/StepsTest.php

# Run a specific test
./vendor/bin/pest --filter "test name"

# Coverage gate — CI enforces this on the 8.4 job (needs a driver: pcov or xdebug)
./vendor/bin/pest --coverage --min=63

# Static analysis — PHPStan runs at level 5 (config in phpstan.neon)
./vendor/bin/phpstan analyse --memory-limit=1G

# Automated refactoring — semantic only; check with --dry-run, drop the flag to apply
./vendor/bin/rector process --dry-run

# Code formatting
./vendor/bin/pint
```

Quality stack runs **Rector → Pint → PHPStan**: Rector owns semantic transforms, Pint owns
formatting (the two never overlap), PHPStan gates. All three are CI gates on `analyse.yml`;
the Rector check is a blocking `--dry-run`, so apply any Rector suggestion locally before pushing.

## Architecture

### Entry point

- `yolo` — CLI entry script that bootstraps the Symfony Console application
- `src/Yolo.php` — registers all commands with the Symfony `Application`

### Commands (`src/Commands/`)

All commands extend `Command` (base) or, for multi-step work, `SteppedCommand` / `SyncSteppedCommand`.

- **`Command`** (`Command.php`) — base class. Handles AWS auth + service registration (`RegistersAws`), `yolo.yml`
  manifest checks, environment validation, and an STS account-vs-profile guard (`ensureAccountMatchesProfile`).
- **`SteppedCommand`** — runs an ordered `$steps` array of `Step` classes with progress tracking and status reporting
  (via the `RunsSteppedCommands` concern).
- **`SyncSteppedCommand`** — a `SteppedCommand` whose steps are declared as scope-labelled `domains()`. Adds the
  `--check`, `--force`, `--no-progress`, and `--tenant=<id>` options and an `environment` argument. There is no
  `--dry-run`: sync is always approve-before-apply (it prints the full plan before its confirm gate, so declining is
  the preview), and `--check` is the non-interactive plan-only/fail-on-drift form for CI.

The full command set: `init`, `env:pull`, `env:push`, `build`, `deploy`, `status`, `run`, `scale`, and the
scope-grouped `sync` / `audit` verbs below.

### Sync is scope-first (`sync` / `sync:account` / `sync:environment` / `sync:app`)

Sync commands are grouped by **ownership scope**, not by AWS service. Each resource declares its scope once
(`Resource::scope()` → `Enums\Scope`), and that single declaration drives its name, its tags, and which sync tier is
allowed to write it:

| Command | `Scope` | Blast radius | Examples |
| --- | --- | --- | --- |
| `sync:account` | `Account` | the whole AWS account | GitHub OIDC provider |
| `sync:environment <env>` | `Env` | every app in the environment | VPC, subnets, IGW/routes, RDS SG, SNS topic, shared ECS execution role, ALB + `:80` and `:443` listeners |
| `sync:app <env>` | `App` | one app | Storage, app IAM (deployer + per-app ECS task role + `task-role-policies`), Fargate (cluster/service/task def), CDN, IVS, mode-aware Queue/DNS |

`sync` orchestrates **account → environment → app** in dependency order. `sync:app` only depends on and *additively
attaches to* shared infra (its SNI cert + listener-rule on the env `:443` listener, its 3306 ingress on the env RDS
SG) — never mutating the shared resource itself, so the shared tier keeps a single writer. Two env-scope resources
(the HTTPS `:443` listener and the RDS SG) are bootstrapped from `sync:app` by exception because their creation has
a per-app trigger — the listener needs a first SNI cert, the SG needs a task SG to authorise — but both are tagged
env-scope, created-if-missing, and never mutated by `sync:app`.

### Audit is scope-first too (`audit` / `audit:environment` / `audit:app`)

Read-only counterpart to `sync` with the same scope split. `audit <env>` queries every resource tagged
`yolo:environment=<env>` via the Resource Groups Tagging API, classifies each as `ok` or `unexpected`, and
renders them grouped by scope. Audit is an ownership/inventory check, **not** a config check — it reads tags, the
ARN service and whether the owning app's cluster is live, and never compares a resource's attributes against the
manifest (that's `sync`'s job, where "drift" means attribute mismatch). Sync stamps a positive ownership marker
on everything it creates — `yolo:app=<app>` for App-scope, `yolo:scope=env`/`=account` for shared infra — so:

- `ok` — `yolo:app` points at a live app, or `yolo:scope=env`/`=account` is present (declared shared infra)
- `unexpected` — found in the env's tag namespace but not accounted for; a `reason` column says why:
  - `no ownership tag` — no `yolo:app`/`yolo:scope` marker (hand-rolled, or alpha-era debris)
  - `service no longer provisioned` — YOLO-owned but of an AWS service with no `Resources/` class, so sync would never recreate it (e.g. the DynamoDB sessions table left behind after DynamoDB support was removed). Surfaced via `Audit::SERVICE_BY_RESOURCE_GROUP`, whose keys mirror the `src/Resources/*` dirs (enforced by a test), so dropping a service dir auto-surfaces its leftovers and adding one fails until catalogued — no managed service is ever false-flagged
  - `app cluster gone` — YOLO-owned, managed service, but `yolo:app` points at an app whose Fargate cluster is gone

`audit:environment <env>` narrows to env-tier rows; `audit:app <env> <app>` narrows to one app. `--unexpected`
is a universal flag that filters to just the rows needing attention.

### Steps (`src/Steps/`)

Steps are the atomic units of work. Each implements `Step` (`__invoke(array $options): StepResult`) and returns a
`StepResult` enum (`CREATED` / `SYNCED` / `WOULD_CREATE` / …). Organised by **execution context** (not AWS service —
that axis belongs to Resources):

- `Build/`, `Deploy/` — lifecycle phase: build the image / run the deployment (see flow below)
- `Sync/Account/`, `Sync/Environment/`, `Sync/App/` — the sync steps, grouped by the **scope** that writes them, so
  each dir mirrors its `sync:account` / `sync:environment` / `sync:app` command's `domains()`. `Sync/App/{Solo,Tenant,
  Landlord}/` holds the app-mode variants (single-tenant vs multi-tenant fan-out). The two by-exception env-scope
  resources bootstrapped from `sync:app` (the `:443` listener, the RDS security group) live under `Sync/App/`.

A sync step is typically thin: it `use`s the `SynchronisesResource` trait and delegates to a `Resource`
(e.g. `return $this->syncResource(new TargetGroup(), $options);`). Steps decide *when* to create/sync; Resources
decide *what* the resource is.

### Resources (`src/Resources/`)

A `Resource` is the desired-state definition of one AWS thing, independent of the step that drives it. It owns its
`name()`, `scope()`, `tags()`, `exists()`, `arn()`, `create()`, and `synchroniseTags()`. Organised strictly by AWS
service — every `Resources/{Service}/` dir maps 1:1 to an `Aws/{Service}` wrapper (`Ec2/`, `Ecs/`, `ElbV2/`, `Ecr/`,
`S3/`, `Rds/`, `Sns/`, `Sqs/`, `Iam/`, `Acm/`, `Route53/`, `CloudWatch/`, `CloudWatchLogs/`, `EventBridge/`,
`CloudFront/`).

- **`ResolvesTags`** (trait) — derives the resource `Name`, the `yolo:app` owner tag (App scope only), and the
  scope-aware `keyedName()` from `scope()`.
- **`SynchronisesConfiguration`** (interface) — opt-in for resources whose live config can drift after creation
  (e.g. CloudFront distribution, ALB attributes, target-group settings). `SynchronisesResource::syncResource()` calls
  `synchroniseConfiguration()` on an *existing* resource in addition to tag sync, so a changed default reaches an
  already-provisioned resource.

(Distinct from `src/Audit/` — that looks up *live* AWS state rather than declaring desired state.)

### AWS clients (`src/Aws/` + `Aws.php`)

`Aws.php` is the facade that registers and returns SDK clients (via the `RegistersAws` concern, environment-aware) and
holds tag/helper utilities (`Aws::tags()`, `Aws::expectedTags()`, `Aws::accountId()`, …). `src/Aws/*` are thin
per-service wrappers (`Ecs`, `ElbV2`, `S3`, `Iam`, `Route53`, `Acm`, `Sqs`, `Sns`, `CloudWatch`, `CloudWatchLogs`,
`Ec2`, `Ecr`, `Rds`, `EventBridge`, `CloudFront`, `ResourceGroupsTaggingApi`) — these replaced the old per-service
`Uses*` concern traits.

### Contracts (`src/Contracts/`)

Interfaces a step implements to declare its execution context:

- `Step` — the base step contract
- `RunsOnBuild` / `RunsOnAws` / `RunsOnAwsWeb` / `RunsOnAwsQueue` / `RunsOnAwsScheduler` — where a step runs
- `ExecutesTenantStep` / `ExecutesSoloStep` / `ExecutesMultitenancyStep` / `ExecutesWebStep` / `ExecutesCommandStep` /
  `ExecutesIvsStep` — per-tenant fan-out and app-mode gating
- `HasSubSteps` — step contains sub-steps (e.g. manifest build commands)
- `LongRunning` — step blocks on a slow AWS waiter (cache cluster, sessions table, deploy task); the runner shows
  its `patienceMessage()` and ticks an elapsed-time heartbeat (via `WaitReporter` + `Aws::waitFor`) so the progress
  bar keeps moving instead of freezing mid-wait

### Concerns (`src/Concerns/`)

Orchestration traits (per-service AWS interaction now lives in `src/Aws/*`, not here): `RunsSteppedCommands` (step
execution + progress UI), `SynchronisesResource` (create-or-sync), `RegistersAws` (env-aware client registration),
`RendersServiceStatus` (gathers + renders the live `status` dashboard and the end-of-deploy recap, shared by
`StatusCommand` and `DeployCommand`), `SyncsRecordSets`, `DetectsSubdomains`,
`ChecksIfCommandsShouldBeRunning`, `HasAfterCallbacks`, `ParsesOnlyOption`.

### Configuration & helpers

- `Manifest.php` — reads/writes `yolo.yml`; `Manifest::tenants()`, `Manifest::isMultitenanted()`, and
  `Manifest::has('tasks.web')` drive app mode and step fan-out
- `Paths.php` — centralises filesystem path and S3 key resolution
- `Helpers.php` — container access, `environment()`, `keyedResourceName()`, `version()`
- `Enums/Scope.php` — `App` / `Env` / `Account` ownership scope (single source of truth; replaced the `AppScoped`
  marker + `keyedResourceName(exclusive:)` bool)
- `ShutdownTimings.php` — single source of truth for the graceful-drain windows (web/queue/scheduler
  `shutdown-grace-period`), shared by the supervisord config, the ECS `stopTimeout`, and the ALB deregistration delay

### Key patterns

1. Commands extend `SteppedCommand`; sync commands declare scope-labelled `domains()` of `Step` classes and
   `SyncSteppedCommand::handle()` runs them in order.
2. A `Step` delegates to a `Resource` via `SynchronisesResource`: `exists()` ? sync tags (and config) : `create()`.
3. A `Resource` declares its `Scope` once; its name, tags, and the sync tier that writes it all follow from that.
4. AWS SDK access goes through the `src/Aws/*` wrappers, registered via `RegistersAws` based on environment.
5. Multi-tenancy fans a step out over `Manifest::tenants()` (`ExecutesTenantStep`); `--tenant=<id>` narrows the
   fan-out to a single tenant (for a single-tenant cutover). There is no `sync:tenant` / `deploy:tenant` verb —
   tenancy is a step-level concern, not a command.

### Build & deploy flow

`yolo build` (`BuildCommand`) prepares a Docker image: purge the build dir, pull and stage the env file
(`ConfigureEnvAndVersionStep` bakes `ASSET_URL`/version, `CreateTemporaryEnvStep` → run build hooks via
`ExecuteBuildStepsStep` → `RestoreTemporaryEnvStep` renames the env back to `.env`), generate the container entrypoint
+ supervisord config (`GenerateEntrypointScriptStep`, `GenerateSupervisorConfigStep`), then log in to ECR, build the
image, and push it.

`yolo deploy` (`DeployCommand`) runs `build` first, then: push built assets to S3 → register a new ECS task-definition
revision → run deploy hooks (migrate, etc.) as a one-off `ecs:RunTask` (`ExecuteDeployStepsStep`) → update the ECS
service → `WaitForDeploymentHealthyStep` (the ECS deployment circuit breaker fast-fails and auto-rolls-back on a
broken deploy) → UPSERT the Route 53 record(s) once healthy. Once the rollout settles it prints an end-of-deploy
recap — the per-group summary table + CloudWatch dashboard link from the `RendersServiceStatus` concern.

`yolo status` (`StatusCommand`) is the read-only live dashboard built on the same `RendersServiceStatus` concern: per
group (web/queue/scheduler) it reads ECS, Application Auto Scaling and CloudWatch to show what's running, the task
spec, running/desired counts, scaling bounds + policies, current load against the CPU target, and a progress panel
for any in-flight rollout, plus a deep link to the app's CloudWatch dashboard. It polls and redraws until quit;
`--snapshot` (and any non-interactive shell) renders one frame.

The container runs **supervisord** as its process tree: FrankenPHP for web, queue workers, and a busybox `crond`
driving `schedule:run` (the scheduler is cron, not `schedule:work`). The entrypoint supervises the CMD and traps
SIGTERM so the web tier keeps serving across the ALB drain window before forwarding the stop — see `ShutdownTimings`
for the grace-period knobs.
