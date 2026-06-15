<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marker for a command that provisions or rescales infrastructure — the `sync`
 * family and `scale`. YOLO runs these under the Admin tier: it assumes the
 * `yolo-{env}-admin-role` and re-registers every AWS client against the resulting
 * scoped token, so the run is capped to YOLO's own blast radius (the services YOLO
 * provisions, with IAM fenced to `yolo-*`) — never the operator's broader identity.
 *
 * Self-activating with no bootstrap paradox: the first `yolo sync` of an
 * environment runs on the operator's profile (the role doesn't exist yet) and
 * creates the role; every sync after that mints it. Because the plan pass forks,
 * minting happens once in the parent before the fork — each worker inherits the
 * assumed credentials and re-resolves its clients against them.
 */
interface AdminCommand {}
