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

## External — bring your own network (transitional or permanent)

A database hosted outside the env VPC keeps working: declare it via `database:` and wire the network path yourself. The common shape is **VPC peering** — peer the external VPC with the env VPC, route between them, and allow `3306` on the database's security group from the app's task SG (same-region peering supports SG references; otherwise use the env VPC's CIDR). Audit reports the posture as *externally managed* — informational, never a deploy blocker — and still runs the reachability and deletion-protection checks against it.

This is the natural transitional posture while migrating a database into the managed end-state: peer first (app keeps working), then move the data (snapshot-restore or replication) into an instance launched per the managed checklist above, re-point `database:` (and the app's `DB_HOST`), and retire the peering.

Adopting existing infrastructure wholesale? The [`rds.subnet` / `rds.security-group` / `private-subnets`](/reference/manifest#adopting-existing-infrastructure-advanced) manifest keys point YOLO at groups and subnets you already own (`CUSTOM_MANAGED` — never mutated).

## Exposed — what audit exists to catch

`PubliclyAccessible` on means the database has an internet-facing endpoint whose only defence is its security group — one permissive ingress rule away from the open internet. Audit classifies it **EXPOSED** and warns on every run, whichever VPC it's in. The fix is either disabling public access (an RDS modify, no downtime for most engines) or migrating into the private tier; the laptop access that public endpoints used to justify is what [`db:tunnel`](#reaching-a-private-database) replaces.

## Reaching a private database

A managed database has no public path by design, so [`yolo db:tunnel <env>`](/reference/commands#yolo-db-tunnel) provides the laptop route: an SSM port-forwarding session through a running web task to the database on `3306`, served locally on `13306` (`--port` to change). Point your database client at `127.0.0.1:13306` with the app's usual credentials. It rides the same task-side ECS Exec plumbing as `yolo run`; caller-side it needs `ssm:StartSession` — scope that grant tightly, since a port-forward's destination is client-chosen.

## What audit checks, at a glance

- **Deletion protection** — off is an **error** (audit exits non-zero); unreadable is a warning.
- **Network posture** — managed / external / EXPOSED as above; EXPOSED is a warning.
- **Task reachability** — no `3306`-from-task-SG rule on any attached SG is a warning.
- **Topology basics** — engine, version, size, Multi-AZ, Aurora writer/readers: informational.

Teardown honours the same boundary: `destroy:environment` refuses to reclaim the network shell while any database is still attached to the VPC — snapshot and drop it out-of-band first. The database is never YOLO's to delete.
