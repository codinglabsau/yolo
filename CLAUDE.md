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

## Commands

```bash
# Run tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Arch/StepsTest.php

# Run a specific test
./vendor/bin/pest --filter "test name"

# Static analysis (parallel workers may need more than the 128M default memory limit)
./vendor/bin/phpstan analyse

# Code formatting
./vendor/bin/pint
```

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
  `--dry-run`, `--force`, `--no-progress`, and `--tenant=<id>` options and an `environment` argument.

The full command set: `init`, `env:pull`, `env:push`, `build`, `deploy`, `run`, `audit`, and the scope-grouped sync
commands below.

### Sync is scope-first (`sync` / `sync:account` / `sync:platform` / `sync:app`)

Sync commands are grouped by **ownership scope**, not by AWS service. Each resource declares its scope once
(`Resource::scope()` → `Enums\Scope`), and that single declaration drives its name, its tags, and which sync tier is
allowed to write it:

| Command | `Scope` | Blast radius | Examples |
| --- | --- | --- | --- |
| `sync:account` | `Account` | the whole AWS account | GitHub OIDC provider |
| `sync:platform <env>` | `Env` | every app in the environment | VPC, subnets, IGW/routes, RDS SG, SNS topic, shared task/exec IAM roles, ALB + `:80` listener |
| `sync:app <env>` | `App` | one app | Storage, app IAM, Fargate (cluster/service/task def + `:443` listener + RDS-SG ingress), CDN, IVS, mode-aware Queue/DNS |

`sync` orchestrates **account → platform → app** in dependency order. `sync:app` only depends on and *additively
attaches to* shared infra (a listener rule, an SNI cert) — it never mutates it, so the shared tier keeps a single
writer. Two env-named resources (the HTTPS `:443` listener and the RDS security group) are provisioned by `sync:app`
by exception, because their creation has a per-app dependency; both are created-if-missing and never mutated.

### Steps (`src/Steps/`)

Steps are the atomic units of work. Each implements `Step` (`__invoke(array $options): StepResult`) and returns a
`StepResult` enum (`CREATED` / `SYNCED` / `WOULD_CREATE` / …). Organised by domain:

- `Build/`, `Deploy/` — build the image and run the deployment (see flow below)
- `Network/` — VPC, subnets, security groups, route tables
- `Fargate/` — ECR repository, ECS cluster/service/task definition, target group, listeners
- `Iam/` — IAM roles and policies
- `Storage/`, `CloudFront/`, `Logging/` — S3 buckets, the asset CDN distribution, IVS CloudWatch/EventBridge logging
- `Solo/` / `Tenant/` / `Landlord/` — app-mode-specific steps (single-tenant vs multi-tenant)
- `Ensures/` — preflight existence checks

A sync step is typically thin: it `use`s the `SynchronisesResource` trait and delegates to a `Resource`
(e.g. `return $this->syncResource(new TargetGroup(), $options);`). Steps decide *when* to create/sync; Resources
decide *what* the resource is.

### Resources (`src/Resources/`)

A `Resource` is the desired-state definition of one AWS thing, independent of the step that drives it. It owns its
`name()`, `scope()`, `tags()`, `exists()`, `arn()`, `create()`, and `synchroniseTags()`. Organised by service
(`Fargate/`, `Network/`, `Iam/`, `Storage/`, `Acm/`, `Route53/`, `Sqs/`, `CloudWatch/`, `EventBridge/`, `Logging/`,
`CloudFront/`).

- **`ResolvesTags`** (trait) — derives the resource `Name`, the `yolo:app` owner tag (App scope only), and the
  scope-aware `keyedName()` from `scope()`.
- **`SynchronisesConfiguration`** (interface) — opt-in for resources whose live config can drift after creation
  (e.g. CloudFront distribution, ALB attributes, target-group settings). `SynchronisesResource::syncResource()` calls
  `synchroniseConfiguration()` on an *existing* resource in addition to tag sync, so a changed default reaches an
  already-provisioned resource.

(Distinct from `AwsResources` and `src/Audit/` — those look up *live* AWS state rather than declaring desired state.)

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

### Concerns (`src/Concerns/`)

Orchestration traits (per-service AWS interaction now lives in `src/Aws/*`, not here): `RunsSteppedCommands` (step
execution + progress UI), `SynchronisesResource` (create-or-sync), `RegistersAws` (env-aware client registration),
`SyncsRecordSets`, `ResolvesDatabases`, `DetectsSubdomains`, `ChecksIfCommandsShouldBeRunning`, `HasAfterCallbacks`,
`ParsesOnlyOption`, `EnsuresResourcesExist`, `UsesIam`.

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
broken deploy) → UPSERT the Route 53 record(s) once healthy.

The container runs **supervisord** as its process tree: FrankenPHP for web, queue workers, and a busybox `crond`
driving `schedule:run` (the scheduler is cron, not `schedule:work`). The entrypoint supervises the CMD and traps
SIGTERM so the web tier keeps serving across the ALB drain window before forwarding the stop — see `ShutdownTimings`
for the grace-period knobs.
