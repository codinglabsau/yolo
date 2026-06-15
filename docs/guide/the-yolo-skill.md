# The `/yolo` Skill

YOLO ships an AI **skill** and a **Boost guideline** inside the composer package, so an agent (Claude Code, or anything reading [Laravel Boost](https://github.com/laravel/boost) guidelines) can operate a YOLO environment knowledgeably and safely — without you hand-maintaining a copy somewhere.

The design rule is a hard split:

- **YOLO is a dumb data-pipe.** Its read commands emit machine-readable JSON; it never calls an AI.
- **The skill is the brain.** It reads that JSON, reasons about health, drift, scaling and inventory, and *proposes* changes. Every mutation stays human-gated.

## What ships

Both artefacts live under `resources/` (which the package exports — unlike `docs/` and `tests/`), so they travel with `composer require codinglabsau/yolo`:

| Artefact | Path in the package | Role |
|---|---|---|
| Skill | `resources/boost/skills/yolo/SKILL.md` | The `/yolo` agent skill — the brain |
| Guideline | `resources/boost/guidelines/yolo.blade.php` | Always-on YOLO context for any agent in a Boost app |

There is deliberately **no dotfiles / hand-copied skill** — the package is the single source of truth, so the skill is always version-accurate with the YOLO you have installed.

## The guideline (works today)

Laravel Boost discovers `resources/boost/guidelines/*.blade.php` in your **direct** Composer dependencies — no service provider or registration needed; the directory existing in `vendor/codinglabsau/yolo/` is the whole mechanism. It's **opt-in at install time**, not automatic on `composer require`: run `boost:install` (or re-run it after adding YOLO) and tick **`codinglabsau/yolo`** in the *"Which third-party AI guidelines do you want to install?"* prompt. Boost then composes the guideline into your app's `CLAUDE.md` / `AGENTS.md`. It orients the agent on the command surface, the manifest essentials, the read-only `--json` data-pipe, and the safety rule that infrastructure mutations are human-gated.

## The skill

The skill is a standard Agent Skill (the same `SKILL.md` frontmatter format Claude Code uses, which Boost adopts for skill distribution). Boost installs package-provided skills for you; on a Boost version without skill installation, symlink it from the package as an interim:

```bash
ln -s "$(pwd)/vendor/codinglabsau/yolo/resources/boost/skills/yolo" ~/.claude/skills/yolo
```

### Invoking it

- **`/yolo`** — a full sweep: read `status`/`audit` (and a `sync --check`) across the environments and report a tight green / needs-attention summary.
- **`/yolo <question>`** — a specific ask ("is prod scaling healthy?"); it pulls only the data the question needs.
- **`/loop /yolo`** — an attended copilot that stays quiet while everything's green and speaks up when a signal crosses a line.

## The data-pipe the skill reads

These commands are read-only and scriptable (non-zero exit signals a problem):

| Command | Emits |
|---|---|
| [`status <env> --json`](/reference/commands#yolo-status) | `{app, environment, groups[]}` — per-group tasks, spec, revision, version, rollout, scaling, load. Non-zero if a deploy is failed. |
| [`audit <env> --json`](/reference/commands#yolo-audit) | `{environment, liveApps[], okCount, unexpectedCount, resources[]}` — ownership/inventory check. |
| [`sync <env> --check`](/reference/commands#yolo-sync) | The read-only plan; non-zero exit on [drift](/guide/provisioning). |
| [`services <env> --json`](/reference/commands#yolo-services) | The [service-lifecycle](/guide/provisioning#the-service-lifecycle) gate as data. |

## Safety

The skill is read-first. It will run the `--json` reads and `sync --check` freely, but it **never** runs a mutation — `deploy`, `rollback`, [`scale`](/guide/scaling), `sync` (apply), `env:push` — on its own. It prepares the command for you to run, or lands the change as a PR for a human to merge and deploy. This matches YOLO's own approve-before-apply posture for [provisioning](/guide/provisioning).

That read-first posture is also enforced below the convention line. Once the [`yolo-{env}-observer-role`](/guide/provisioning) is provisioned, YOLO's read commands (`status`, `audit` and friends) **mint a scoped token** by assuming that role — the developer still authenticates as themselves, but the command is capped to the read-only observer policy by construction, so it can't mutate even if the developer's own identity could. It's self-activating (a no-op until the role exists) and fail-open (any problem minting falls back to the developer's profile rather than blocking the read).
