<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Marks a resource YOLO must NEVER delete — the bring-your-own application data
 * bucket ({@see S3\S3Bucket}) today. The deliberate inverse of {@see Deletable}:
 * a class may implement one or the other, never both (enforced by
 * tests/Arch/UndeletableTest.php).
 *
 * This is belt-and-braces, not the only guard. The data bucket is protected at
 * three layers: it simply isn't {@see Deletable} (so the typed teardown engine
 * can't accept it), {@see SynchronisesResource::teardownResource()}
 * hard-fails on anything Undeletable, and {@see \Codinglabs\Yolo\Aws\S3::deleteBucket()}
 * refuses the data bucket by name — so no code path, present or future, can remove it.
 * RDS gets the same guarantee from the other direction: it is never modelled as a
 * deletable resource and no destructive RDS call may appear in src/ (enforced by
 * tests/Arch/NeverDeletesDatabaseTest.php).
 */
interface Undeletable {}
