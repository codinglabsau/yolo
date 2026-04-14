# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

YOLO is a PHP CLI tool for deploying Laravel applications to AWS. It provisions and manages AWS resources (VPCs, EC2,
ALB, autoscaling groups, CodeDeploy, S3, etc.) and handles zero-downtime deployments.

## Rules
- 

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

# Static analysis
./vendor/bin/phpstan analyse

# Code formatting
./vendor/bin/pint
```

## Architecture

### Entry Point

- `yolo` - CLI entry script that bootstraps the Symfony Console application
- `src/Yolo.php` - Registers all commands with the Symfony Application

### Commands (`src/Commands/`)

All commands extend `Command` (base class) or `SteppedCommand` (for multi-step operations).

- **Base Command** (`Command.php`) - Handles AWS authentication, environment validation, and manifest checks
- **SteppedCommand** - Executes a series of `Step` classes with progress tracking and status reporting

Key commands: `build`, `deploy`, `sync`, `sync:network`, `sync:compute`, `sync:standalone`, `sync:tenant`, `stage`,
`image:create`, `env:push`, `env:pull`

### Steps (`src/Steps/`)

Steps are the atomic units of work. Each step implements the `Step` interface and returns a `StepResult` enum.

Steps are organized by domain:

- `Build/` - Build process steps
- `Deploy/` - Deployment steps
- `Network/` - VPC, subnet, security group provisioning
- `Compute/` - EC2, autoscaling setup
- `Iam/` - IAM roles and policies
- `Standalone/` / `Tenant/` / `Landlord/` - App-type specific steps

### Contracts (`src/Contracts/`)

Interfaces that steps implement to indicate execution context:

- `RunsOnBuild` - Runs during local build
- `RunsOnAws` - Runs on AWS instances
- `RunsOnAwsQueue` / `RunsOnAwsScheduler` / `RunsOnAwsWeb` - Runs on specific worker groups
- `ExecutesTenantStep` - Runs once per tenant in multi-tenant apps
- `HasSubSteps` - Step contains sub-steps (e.g., manifest build commands)

### Concerns (`src/Concerns/`)

Traits for AWS service interactions: `UsesEc2`, `UsesIam`, `UsesAutoscaling`, `UsesCodeDeploy`, `UsesRoute53`, etc.

### Configuration

- `Manifest.php` - Reads/writes `yolo.yml` configuration
- `Paths.php` - Centralizes file path resolution
- `Helpers.php` - Utility functions and container access

### Key Patterns

1. Commands define a `$steps` array of Step classes to execute
2. `RunsSteppedCommands` trait handles step execution with progress UI
3. AWS SDK clients are registered via `RegistersAws` trait based on environment
4. Multi-tenancy is supported through tenant-aware steps that iterate over `Manifest::tenants()`

### StartCommand Step Ordering

The `start` command runs on AWS instances during CodeDeploy. Step order matters:

1. `ProvisionDirectoriesStep` — creates `~/yolo/{app}/` and `/var/log/yolo/{app}/` (must run first)
2. File-writing steps — write configs, scripts, cron entries (assume directories exist)
3. `SetOwnershipAndPermissionsStep` — `chown -R` on `/home/ubuntu`, `/var/log/yolo`, `/var/www` (must run last, after all root-owned files are written)
4. `RestartServicesStep` → warm → load balancing

### File System Conventions

- `~/yolo/{keyed-resource-name}/` — YOLO working directory per app (scripts, dumps)
- `/var/log/yolo/{keyed-resource-name}/` — YOLO logs per app
- `/var/www/{name}/` — Laravel application code
- `/etc/cron.d/`, `/etc/supervisor/conf.d/`, `/etc/logrotate.d/` — system configs use `keyedResourceName()`
- `Paths::yoloDir()` and `Paths::logDir()` centralise path construction
