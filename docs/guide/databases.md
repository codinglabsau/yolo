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

From the [`peering`](/reference/manifest#the-environment-manifest-yolo-environment-environment-yml) entry, `sync:environment` reconciles the whole bridge: the peering connection created and accepted (same-account), routes both ways (the peer's CIDR into the env's public route table, the env's CIDR into the peer's main route table), and DNS resolution over the peering so the RDS hostname resolves to its private IP from inside the env VPC. From `database:`, `sync:app` discovers the external instance's security group live and writes the same additive `3306`-from-task-SG rule the managed path gets — nothing about the foreign network is ever declared, so nothing can go stale. (A database carrying several security groups is ambiguous — sync warns and leaves that one rule to you; `yolo audit` verifies whichever rule exists.)

Audit reports the posture as *externally managed* — informational, never a deploy blocker — and still runs the reachability and deletion-protection checks against it. The external-ingress reconcile is skipped by the deploy gate for the same reason (`yolo sync` is its drift check).

This is the natural transitional posture while migrating a database into the managed end-state: **declare the peering first** (the app keeps working and public access can be disabled immediately), then move the data (snapshot-restore or replication) into an instance launched per the managed checklist above, re-point `database:` (and the app's `DB_HOST`), and **remove the `peering` entry** — the next sync tears the bridge down and reclaims the routes.

There is deliberately no way to point YOLO at someone else's network: **YOLO owns the network layer, full stop** — that ownership is what makes every posture verdict, security assumption and teardown guarantee on this page true. An external database is reached by peering, never by adoption.

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
