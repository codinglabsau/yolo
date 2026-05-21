<?php

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A YOLO-managed AWS resource — desired-state definition, independent of the
 * step that orchestrates its lifecycle. The resource owns its identity (name,
 * tags, ARN lookup), its create payload, and its tag-sync behaviour. Steps
 * decide WHEN to create / sync; the resource decides WHAT a created or synced
 * version of itself looks like.
 *
 * Distinct from `AwsLookups` (state-lookup facade for the live AWS environment).
 */
interface Resource
{
    public function name(): string;

    /**
     * Associative {Key => Value} tag map. The `yolo:environment` baseline is
     * added by `Aws::expectedTags()` at write time — implementations only
     * declare the resource-specific tags (typically just `Name`).
     *
     * @return array<string, string>
     */
    public function tags(): array;

    public function exists(): bool;

    /**
     * @throws ResourceDoesNotExistException
     */
    public function arn(): string;

    public function create(): void;

    public function synchroniseTags(): void;
}
