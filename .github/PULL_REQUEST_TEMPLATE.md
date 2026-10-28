<!--
This repository is public, and so is a PR's edit history. Motivate the change from the
problem class, never an incident, and use placeholders (`my-app`, `acme`, `example.com`).
No client or app names, account ids, ARNs, real domains, ticket keys or people.
-->

### What is this?
One or two plain sentences on what this PR changes, for someone who reads nothing else.

### Why is this needed?
**One-sentence headline: what's broken, missing or worth improving, and who it affects.**

The reasoning, as a paragraph or list: the evidence behind it, and what's inferred rather than proven.

### What did you implement?
- The key changes, in a few bullets.

### How was it verified?
- Pest, Rector, Pint and PHPStan; the docs build for any `docs/` change.
- For a sync step or reconciler: what each AWS read returns when nothing exists yet, and whether a real first sync converged (run twice, second plan clean). MockHandler tests prove request shape only.
- Anything left unverified, such as a server-side contract the tests can't reach.

### Risks and reviewer notes
- Blast radius by scope (account, environment, app), IAM grants added or changed, output that changes for apps that don't opt in, and anything else worth a closer look.

### Upgrade notes
- What an app or environment needs after bumping: a `sync` (and which tier) before the next deploy, `yolo permissions`, manifest or Dockerfile changes, a rebuild. "None" is a fine answer.
