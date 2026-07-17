<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

/**
 * Marker: a resource sync may legitimately find pre-existing WITHOUT the
 * `yolo:scope` ownership marker and adopt by stamping its tags. Reserved for
 * infrastructure where "already exists but isn't ours yet" is an expected,
 * healthy state because the resource is a singleton beyond YOLO's naming
 * authority — a hosted zone (one per domain, often pre-created at the
 * registrar), the GitHub OIDC provider (AWS allows exactly one per account,
 * and non-YOLO CI may have created it first).
 *
 * Everything else: an existing same-named resource with no ownership marker
 * is a stranger — most dangerously another deployment tool's live resource
 * sharing the account — and sync refuses to adopt it rather than stamping
 * YOLO tags on infrastructure it doesn't own (see
 * SynchronisesResource::synchroniseOwnedTags()). The BYO app data bucket
 * needs no marker: it is deliberately never tagged, so there is no adoption
 * to refuse.
 */
interface Adoptable {}
