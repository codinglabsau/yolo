# Services

A **service** is an opt-in AWS capability an app consumes — live video, search, transcoding, image analysis. An app declares the ones it uses by bare name in its [`yolo.yml`](/reference/manifest#services):

```yaml
services: [ivs, typesense]
```

An entry is just a name. All **shape** — sizing, versions, retention — is either hardcoded or lives in [the environment manifest](#environment-backed-services), never the app manifest, so two apps can never declare competing configuration for shared infrastructure. Unknown names, duplicates, or anything but a flat list hard-fail validation.

## The two tiers

Every service is one of two kinds, and the kind decides where it's shaped, which sync provisions it, and how far a change reaches:

| Service | Tier | Provisioned by | What the app gets |
|---|---|---|---|
| [`ivs`](#ivs-live-video) | environment-backed | `sync:environment` + `sync:app` | an `ivs:*` task-role grant; a shared per-environment event-logging pipeline |
| [`typesense`](#typesense-the-environment-s-search-cluster) | environment-backed | `sync:environment` + `sync:app` | a shared search cluster; a per-app scoped key + Scout wiring |
| [`mediaconvert`](#mediaconvert-video-transcoding) | app-side | `sync:app` | a per-app MediaConvert role + job IAM |
| [`rekognition`](#rekognition-image-video-analysis) | app-side | `sync:app` | a `rekognition:*` task-role grant |

### App-side services

`mediaconvert` and `rekognition` are a plain per-app claim. Enabling one adds it to the app's `yolo.yml` and `sync:app` grants the IAM the container needs — nothing is shared, there's no environment-manifest half, and the capability is a pay-per-call AWS API the app calls as itself. When an app stops claiming one, its IAM melts away on the next sync.

### Environment-backed services

`ivs` and `typesense` also have a half in [the environment manifest](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml) — a `services.{name}` entry carrying any shape — because the underlying infrastructure is **shared by every app in the environment**. The app's bare claim says *I use this*; the environment manifest says *the environment runs this, shaped like so*. That shared half is governed by [the service lifecycle](#the-service-lifecycle).

## Managing services — `yolo services`

[`yolo services <env>`](/reference/commands#yolo-services) is the front door, and it's self-guiding — so this is the short version:

```bash
yolo services production
```

It prints a `Service · Description · Status` table (Status = whether *this app* uses each one) and a picker. Pick a service to:

- **Enable / disable it for this app** — a surgical edit to your `yolo.yml` `services` list, preserving its comments and formatting. For an app-side service that's the whole change. For an env-backed service, enabling also walks you through its **environment offer** (Typesense's version / nodes / CPU / RAM, pre-filled with defaults) on a *local* copy of the env manifest.
- **Apply** — nothing touches AWS until you say so. An app-side change offers to run `sync:app`; an env-backed change spells out (and offers to run) `environment:manifest:push` then `sync`. Both default to *not* applying, because provisioning is real, billed infrastructure.

For agents and CI there's a non-interactive surface — `--json` to read the gate as data, and `--add` / `--set` / `--remove` to drive the environment offer directly:

```bash
yolo services production --json
yolo services production --add=typesense --set version=30.2 --set nodes=3
yolo services production --remove=typesense
```

See the [`services` command reference](/reference/commands#yolo-services) for every flag.

## The service lifecycle

An environment-backed service (IVS or Typesense) runs while two things are true:

1. **The environment declares it** — `services.{name}` in the env manifest. That entry is the environment's catalogue: the record of what this environment runs and how it's shaped. It stays put even when nothing is using the service — removing it is your call, never an inference.
2. **A running app is using it** — every `deploy` and `sync:app` publishes the app's `services` list into the env config bucket, and only apps with running tasks count (the same liveness test [`audit`](/guide/provisioning#auditing-what-s-deployed) uses) — a dead app can't keep a service alive.

`sync:environment` checks both for every env-backed service, every sync:

| Declared | In use | What sync does |
|---|---|---|
| yes | yes | Provisions it and keeps it reconciled |
| yes | no | Plans the teardown (`WOULD DELETE`, behind the confirm gate) |
| no | no | Tears down whatever still exists; otherwise skips |
| no | yes | **Hard error** — only reachable by editing the bucket manifest by hand, since `environment:manifest:push` refuses to remove a service apps still use |

One deliberate exception: a running app that hasn't deployed since this YOLO release hasn't told the environment what it uses yet — so until every running app has, nothing is created or torn down. Day one nobody has redeployed, and nothing gets torn down by surprise.

Enforcement is hard errors everywhere, never warnings:

- An app that uses a service the environment doesn't declare fails `build`, `deploy` and `sync:app` with the fix spelled out (declare it via the manifest pull/push flow, or take it out of `yolo.yml`). On a greenfield environment whose manifest hasn't been seeded yet, the check defers to the first sync instead of bricking it.
- `environment:manifest:push` refuses to remove a service while running apps still use it — naming them — and likewise while any running app hasn't published what it uses yet.

Retiring a service is therefore self-enforcing, with hard edges the whole way: remove it from each app's `yolo.yml` → `deploy`/`sync:app` (the app's per-service IAM melts away in the same pass) → remove the env-manifest entry and `push` (accepted once nothing is using it) → `sync:environment` plans the teardown for you to confirm.

## IVS — live video

[Amazon IVS](https://aws.amazon.com/ivs/) for live, low-latency video streaming. Environment-backed, but the shared half is deliberately thin: **the app drives IVS itself at runtime** (it creates channels and stream keys on demand), so there's no stable resource to provision per app and nothing to scope the grant to.

- **App grant.** [`services: [ivs]`](/reference/manifest#services) grants the app's ECS task role `ivs:*` (on `*` — channels and stream keys don't exist until the app makes them).
- **The shared event pipeline.** `sync:environment` provisions one event-logging pipeline per environment: a CloudWatch log group (`/aws/ivs/yolo-{env}`, 14-day retention) and an EventBridge rule (`yolo-{env}-ivs-state-change`) matching every `aws.ivs` event in the account, targeting the log group with a resource policy that lets EventBridge write to it. It's one pipeline per environment — not per app — because the `aws.ivs` event stream is account-wide. Cost is negligible.
- **Observability.** Each consuming app's CloudWatch dashboard gains an IVS logs panel sourced from that log group.
- **Manifest shape.** `services.ivs: {}` — no offer keys; the pipeline isn't sized.

## Typesense — the environment's search cluster

Declaring `services.typesense` (a pinned `version` plus optional `cpu`/`memory` per-node sizing, `tasks.*`-style — see [the manifest reference](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml)) gives the environment a self-hosted, three-node [Typesense](https://typesense.org) cluster, shared by every app with `typesense` in its `services` list:

- **Durable by replication, not by disk.** The three single-task ECS services (AZ-spread, one per public subnet, arm64) form a Raft quorum: writes commit on 2-of-3, and a replaced node catches up from the surviving majority over the network — the persistence model that works *with* Fargate's ephemeral storage. Losing one node degrades nothing; losing two degrades to read-only until a node returns. The search index is a rebuildable projection of your database (`scout:import`), never a source of truth.
- **Stable peer addresses** come from a private Cloud Map DNS namespace (`{env}.internal`): each node owns `typesense-{n}.{env}.internal`, re-resolving to its replacement task within seconds.
- **The image is the secret boundary.** Sync seed-generates an admin API key into the env-shared `.env`, then builds `typesense/typesense:{version}` plus a config file carrying that key (and CORS, enabled so browsers can query the nodes directly) into an env-scoped ECR repository, content-tagged by version + a fingerprint of the whole baked config — unchanged inputs never rebuild, and a version bump, key rotation or config change re-tags the image and **rolls the nodes one at a time**, each waiting for stability before the next (the quorum holds throughout). The task definition carries no secret.
- **The env services cluster** (`yolo-{env}-services`) hosts the node tasks, kept apart from the per-app clusters so app liveness derivation never mistakes shared-service tasks for an app — which is also why `services` is a reserved app name.
- Node-to-node Raft traffic (8107) is locked to the node security group itself; the search API (8108) admits the environment's ALB plus each consuming app's task security group.

**Two deliberate traffic paths.** Browsers reach the cluster on **`search.{domain}`** (the env manifest's `domain` — required once the environment runs typesense): an env cert rides the shared `:443` listener via SNI, a Name-tagged listener rule forwards the host to a target group health-checking Typesense's own `/health` (so a catching-up node drops out of rotation while the quorum serves), and a Route 53 alias points the host at the ALB. The nodes answer CORS for any origin so a page on the app's own domain can query `search.{domain}` directly — the browser carries a search-only key, so that key's scope and the rate limit are the controls, not an origin allowlist. App/Scout **indexing stays private, in-VPC** — the build injects the Cloud Map node addresses, so bulk reimports never meet the ALB, the WAF, or its rate budget.

**The WAF knows about search.** Keystroke queries behind CGNAT would false-positive the general per-IP rate rule, so while the search host is active, `yolo-rate-limit` carves it out and a YOLO-owned `yolo-search-rate-limit` rule (1,000 req/min per IP, host-scoped) takes over.

**Keys are scoped per app.** The cluster admin key lives alone in the env-shared `.env` and never leaves the env tier (baked into the env-scoped image; app builds never read it). `sync:app` mints each consuming app **two** keys via the admin API, both restricted to its own `{prefix}*` collections (a leaked key reaches that app's collections only): a **server-side** key (all actions) the app indexes and queries with from PHP, and a **search-only** key (`documents:search`) safe to embed in the page for browser-direct InstantSearch. Both are written into the app's **environment-side `.env`** (`env/.env.{app}` in the env config bucket), beside the env manifest and the per-app claim files. Keeping them there, not in the app's developer `.env` (a per-app config bucket the admin tier running `sync` is fenced from), is what lets `sync` mint them at all — and it isolates each app's keys: the build merges in only its own `env/.env.{app}`, never the admin key or a sibling's. Each key is minted once (rotation = delete the line, re-sync), so an app provisioned before the search key existed gets it backfilled on the next sync; while the cluster isn't up yet, the mint skips with instructions and lands on the next sync. The build also injects `SCOUT_DRIVER=typesense`, `SCOUT_PREFIX`, the private Cloud Map node addresses for server-side indexing (`TYPESENSE_HOST/PORT/PROTOCOL` + the full `TYPESENSE_NODES` list), and the public search host for the browser (`TYPESENSE_SEARCH_HOST/PORT/PROTOCOL`).

**Observability rides the consumer's dashboard** (search node health with the quorum floor annotated, request count + p99, per-node memory/CPU, rate-limit blocks, a Typesense logs panel), and the env SNS topic gets the quorum alarm pair — healthy nodes < 3 warns, < 2 means read-only — plus per-node memory alarms at 80% of the offer.

Sizing rule of thumb: Typesense holds the whole index in memory at ~2–3× the raw indexed size, so a few hundred MB of records fits comfortably on 1 GB nodes; resizing is an env-manifest edit and a sync. Losing all three nodes at once is genuine DR: the index is a rebuildable projection, so `scout:import` from the database restores it.

## MediaConvert — video transcoding

[AWS Elemental MediaConvert](https://aws.amazon.com/mediaconvert/) for file-based video transcoding. App-side only — jobs run on the account's default on-demand queue, so there is no environment-manifest half.

- **App grant.** `sync:app` provisions a per-app IAM role for MediaConvert to assume, and grants the task role the job operations (`CreateJob`, `GetJob`, `ListJobs`, `DescribeEndpoints`) plus `iam:PassRole` locked to that one role and to MediaConvert itself.
- **Build value.** The role's ARN is baked into the build as `AWS_MEDIACONVERT_ROLE_ID`, so the app passes it when it submits a job.
- **Lifecycle.** The role is torn down on the sync after the app stops claiming the service.
- **Observability.** The app's CloudWatch dashboard gains a MediaConvert jobs panel (completed + errored on the account's default queue).

## Rekognition — image & video analysis

[Amazon Rekognition](https://aws.amazon.com/rekognition/) for image and video analysis. App-side only and the lightest service there is — a pure pay-per-call API, so nothing is provisioned at all.

- **App grant.** `sync:app` grants the app's ECS task role `rekognition:*` (on `*`). The detection APIs are resource-less — they operate on request payloads or on S3 objects read with the caller's own credentials, so reads of the app's [`bucket`](/reference/manifest#bucket) ride its existing grant.
- **Observability.** The app's CloudWatch dashboard gains a Rekognition requests panel (by operation: successful, throttled, user and server errors).
