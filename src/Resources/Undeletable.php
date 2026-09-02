<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * A resource YOLO must NEVER delete — the application data bucket. Inverse of
 * {@see Deletable}: a class implements one or the other, never both (enforced by
 * tests/Arch/UndeletableTest.php). Belt-and-braces alongside
 * {@see SynchronisesResource::teardownResource()} hard-failing on it and
 * {@see S3::deleteBucket()} refusing the data bucket by
 * name. RDS gets the same guarantee via tests/Arch/NeverDeletesDatabaseTest.php.
 */
interface Undeletable {}
