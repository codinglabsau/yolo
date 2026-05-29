# Provisioning

`yolo sync` reconciles the AWS resources your app needs with what you've declared in `yolo.yml`. It's idempotent: it looks at what already exists, computes the difference, and makes only the API calls needed to close the gap. Run it as often as you like.

```bash
yolo sync production
```

## Scope-first provisioning

YOLO groups every resource by **ownership scope** — the blast radius if it changes. Each scope has exactly one writer, so an app deploy can never mutate shared infrastructure:

| Command | Scope | Blast radius | Provisions |
|---|---|---|---|
| `yolo sync:account <env>` | **Account** | the whole AWS account | GitHub OIDC provider |
| `yolo sync:environment <env>` | **Environment** | every app in the environment | VPC, subnets, internet gateway & routes, RDS security group, SNS alarm topic, shared ECS task & execution IAM roles, the ALB and its `:80`/`:443` listeners |
| `yolo sync:app <env>` | **App** | one app | S3 buckets, app IAM (deployer role/policy), ECS cluster/service/task definition, target group + listener rule, CloudFront distribution, hosted zone & ACM certificate, SQS queues |

The bare `yolo sync` runs all three **in dependency order** — account, then environment, then app:

```bash
yolo sync production   # account → environment → app
```

`sync:app` only *additively attaches* to shared infrastructure (its SNI certificate and listener rule on the environment's `:443` listener, its `3306` ingress rule on the shared RDS security group). It never modifies the shared resource itself, so the environment tier stays the single writer.

::: tip Why scopes matter
Several apps can share one environment's VPC and load balancer. Because `sync:app` only attaches and never mutates, deploying app B can't break app A's networking. When you're iterating on one app, `sync:app` is faster than a full `sync` — the account and environment tiers rarely change.
:::

## Plan, confirm, apply

`sync` never surprises you. It runs as a three-step flow:

1. **Plan** — YOLO inspects live AWS state and computes what would change, rendering it grouped by scope with per-attribute diffs (`current → desired`).
2. **Confirm** — you're shown the plan and asked to approve. If nothing has drifted, it short-circuits with **"Already in sync"** and exits without touching anything.
3. **Apply** — only the changed steps run.

### Preview with `--dry-run`

To see the plan without the confirm/apply step at all:

```bash
yolo sync production --dry-run
```

This computes and prints the full diff but makes no changes. It's the safe way to see what a `sync` would do before you commit — always dry-run first against an account you care about.

### Skip the prompt with `--force`

In automation, skip the interactive confirmation:

```bash
yolo sync production --force
```

### Narrow to one tenant

For a multi-tenant app, limit the per-tenant steps to a single tenant (e.g. during a single-tenant cutover):

```bash
yolo sync:app production --tenant=acme
```

See the [`sync` command reference](/reference/commands#yolo-sync) for every option.

## Auditing what's deployed

`yolo audit` is the read-only counterpart to `sync`. It queries every resource tagged `yolo:environment=<env>` and classifies each one:

```bash
yolo audit production
```

| Status | Meaning |
|---|---|
| `ok` | Accounted for — `yolo:app` points at a live app, or it carries a `yolo:scope=env`/`=account` marker (declared shared infra). |
| `drift` | `yolo:app` points at an app whose ECS cluster is gone — leftover resources from a removed app. |
| `rogue` | Tagged for the environment but with **no** YOLO ownership marker — hand-rolled infrastructure or alpha-era debris in the environment's namespace. |

Like sync, audit is scope-grouped — narrow it with `audit:environment <env>` or `audit:app <env> <app>`, and add `--drift` to show only the drifted rows:

```bash
yolo audit production --drift
yolo audit:app production myapp
```

Full details in the [audit command reference](/reference/commands#yolo-audit).
