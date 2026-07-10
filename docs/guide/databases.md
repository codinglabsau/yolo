# Databases

YOLO **never creates, modifies, or deletes a database** — not the instance, the cluster, or a snapshot (a CI tripwire enforces it). What YOLO owns is everything *around* the database: the network it should live in, the security group that admits the app, the audit that tells you where it actually sits, and the tunnel that gets you to it. The database itself is always yours.

Declare the database with the manifest [`database:`](/reference/manifest#database) key — a bare instance identifier or a full endpoint hostname. That one key powers the CloudWatch dashboard's Database section, the `yolo status` Database tab, the [`yolo audit`](/reference/commands#yolo-audit) probes, and [`yolo db:tunnel`](/reference/commands#yolo-db-tunnel). Omit it and all four are simply dropped.

## The three postures

`yolo audit` classifies where the declared database actually lives relative to the YOLO network. The classification is **audit-only — it never feeds sync drift**, so no posture ever blocks a deploy (the deploy gate runs `sync --check`, which knows nothing about any of this).

| Posture | Meaning | Audit outcome |
|---|---|---|
| **Managed** | The end-state: env VPC, the private DB subnet group, the YOLO RDS security group | Informational |
| **External** | A different VPC (or hand-wired networking inside the env VPC) — externally managed | Informational — a valid, often transitional, posture |
| **EXPOSED** | `PubliclyAccessible` is on: an internet-facing endpoint, regardless of VPC | **Warning** |

Independent of the classification, audit also warns when **no attached security group allows `3306` from the app's task security group** — the one rule that lets Fargate tasks reach the database. Every cross-service read degrades to *unknown* when the running tier can't make it, and an unknown fact never warns.

## Managed — the end-state

Compute in the public tier, database in the [private subnet tier](/guide/provisioning#the-network): no public IP, no internet route, reachable only via the `3306` ingress rule `sync:app` writes from each app's task SG.

Launching a database into the managed posture (console or CLI — YOLO won't do it for you):

1. **DB subnet group:** `yolo-{env}-private-subnet-group` (provisioned by `sync:environment`).
2. **VPC security group:** `yolo-{env}-rds-security-group` (the task-SG ingress is reconciled onto it by `sync:app`).
3. **Public access: No.** The private subnets have no internet route anyway — public accessibility would only create a dangling public endpoint that audit flags as EXPOSED.
4. **Deletion protection: On.** Audit treats it off as an error, not a warning.

Then set `database:` in the manifest and `yolo sync:app <env>` — the dashboard, status tab and audit pick it up from there. Day-to-day access from a laptop is [`yolo db:tunnel`](#reaching-a-private-database).

## External — declared peering (transitional or permanent)

A database hosted outside the env VPC keeps working, and the bridge to it is **declared, not console-clicked**. Two declarations drive everything:

```yaml
# yolo-environment-{env}.yml — the env manifest (peering is VPC-to-VPC, env-shared)
peering:
  - vpc-0abc123   # the VPC holding the database

# yolo.yml — each app that uses the database, as always
database: my-app-db
```

From the [`peering`](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml) entry, `sync:environment` reconciles the whole bridge, in a deliberate order: the peering connection created and accepted (same-account); routes both ways — the peer's CIDR into every yolo-managed route table (the public tier where the tasks live *and* the private tier where a database lives), the env's CIDR into every peer-VPC route table that actually governs a subnet (the peer's main table only when nothing there is associated); and DNS resolution over the peering enabled **last**, once every route exists — the RDS hostname starts resolving to its private IP from inside the env VPC only when traffic can actually flow, so nothing black-holes mid-sync. From `database:`, `sync:app` discovers the external instance's security group live and writes the same additive `3306`-from-task-SG rule the managed path gets — nothing about the foreign network is ever declared, so nothing can go stale. (A database carrying several security groups is ambiguous — sync warns and leaves that one rule to you; `yolo audit` verifies whichever rule exists.)

Audit reports the posture as *externally managed* — informational, never a deploy blocker — and still runs the reachability and deletion-protection checks against it. The external-ingress reconcile is skipped by the deploy gate for the same reason (`yolo sync` is its drift check).

This is the natural transitional posture while migrating a database into the managed end-state: **declare the peering first** (the app keeps working and public access can be disabled immediately), then move the data (snapshot-restore or replication) into an instance launched per the managed checklist above, re-point `database:` (and the app's `DB_HOST`), and **remove the `peering` entry** — the next sync tears the bridge down in reverse (DNS resolution off, the routes reclaimed on both sides — including the return routes YOLO wrote into the peer's tables — then the connection).

There is deliberately no way to point YOLO at someone else's network: **YOLO owns the network layer, full stop** — that ownership is what makes every posture verdict, security assumption and teardown guarantee on this page true. An external database is reached by peering, never by adoption.

## Exposed — what audit exists to catch

`PubliclyAccessible` on means the database has an internet-facing endpoint whose only defence is its security group — one permissive ingress rule away from the open internet. Audit classifies it **EXPOSED** and warns on every run, whichever VPC it's in. The fix is either disabling public access (an RDS modify, no downtime for most engines) or migrating into the private tier; the laptop access that public endpoints used to justify is what [`db:tunnel`](#reaching-a-private-database) replaces.

## Reaching a private database

A managed database has no public path by design, so [`yolo db:tunnel <env>`](/reference/commands#yolo-db-tunnel) provides the laptop route: an SSM port-forwarding session through a running web task to the database on `3306`, served locally on `13306` (`--port` to change). Point your database client at `127.0.0.1:13306` with the app's usual credentials. It rides the same task-side ECS Exec plumbing as `yolo run`; caller-side it needs `ssm:StartSession` — scope that grant tightly, since a port-forward's destination is client-chosen.

## Rolling a database over

The last step of any migration is repointing the apps at the new database. `DB_HOST` is baked into the image, so the obvious move — change the env, redeploy — has two costs: the full rolling-deploy duration as the write window, and a mixed-fleet moment where old tasks (still on the old host) and new tasks (on the new host) are both writing. If the two databases are still replicating, that split write is how you fork history.

Two shapes, depending on how much that window matters.

**Bake and deploy** is the simple one: [`yolo env:push`](/reference/commands#yolo-env-push) the new `DB_HOST`, deploy, done. The write window is the deploy; the mixed-fleet risk is real but bounded, and for a low-write app it rarely bites. This is the right default when a minute or two of split writes can't hurt you.

**In-place cutover** shrinks the window to the length of a loop over the running tasks — that's [`yolo db:cutover`](/reference/commands#yolo-db-cutover). It reads every running task's current `DB_HOST`, shows you the plan, and on confirmation puts a maintenance page up on every task, then per task patches `.env`, rebuilds the cached config (`php artisan optimize`), and reloads the booted workers (`octane:reload` on web, `queue:restart` on queue) so they actually re-read the change, then brings the page back down. Tasks already on the target are skipped, so a cutover that dies mid-loop is safe to simply re-run.

Every run ends with the verification pass — also available standalone as `db:cutover --verify`, read-only and exiting non-zero on any failure so it can gate automation. It proves independent layers per container — the `.env` line, the *cached* config value the app really uses, a live query, and maintenance-mode-off — and then asserts every container reported the **same** `@@server_uuid`. That cross-container identity check is the one that matters: it catches a single straggler still talking to the old database even when every hostname reads clean.

For the fleet-level view while a migration is in flight, [`yolo db:status`](/reference/commands#yolo-db-status) maps every app in the environment to the `database:` its manifest declares, read from the published claim files — declared truth at a glance, with `db:cutover --verify` as the per-app live proof.

Two things the command warns about but assumes you've done, because they're the sharp edges:

- **Freeze writes on the source first**, so a task the scheduler replaces mid-cutover — or an in-flight queue job — fails loudly instead of silently writing to the old side. If the source is still replicating to the target, remember that `REVOKE` is a binlogged statement and **replicates too**: re-`GRANT` on the target once the revoke arrives, or freeze with `read_only=1` on a parameter group the target does **not** share (a shared parameter group would freeze both instances at once).
- **The in-place flip is transient.** Env lives in the baked image, so any task replaced after the loop boots the *old* host. Follow immediately with `env:push` + a deploy to make it permanent — the flip buys you a controlled window, not a finished state.

Either way, the migration ends the same: re-point `database:` in each app's manifest, remove the [`peering`](#external-declared-peering-transitional-or-permanent) entry if this was a cross-VPC move, and let the next sync tear the bridge down. Audit flips to *managed*.

## What audit checks, at a glance

- **Deletion protection** — off is an **error** (audit exits non-zero); unreadable is a warning.
- **Network posture** — managed / external / EXPOSED as above; EXPOSED is a warning.
- **Task reachability** — no `3306`-from-task-SG rule on any attached SG is a warning.
- **Topology basics** — engine, version, size, Multi-AZ, Aurora writer/readers: informational.

Teardown honours the same boundary: `destroy:environment` refuses to reclaim the network shell while any database is still attached to the VPC — snapshot and drop it out-of-band first. The database is never YOLO's to delete.
