<?php

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A YOLO-managed AWS resource — desired-state definition, independent of the
 * step that orchestrates its lifecycle. The resource owns its identity (name,
 * tags, ARN lookup), its create payload, and its tag-sync behaviour. Steps
 * decide WHEN to create / sync; the resource decides WHAT a created or synced
 * version of itself looks like.
 *
 * Distinct from `AwsResources` (state-lookup facade for the live AWS environment).
 */
interface Resource
{
    public function name(): string;

    /**
     * Ownership scope — the single source of truth (replacing the AppScoped
     * marker + keyedResourceName(exclusive:) bool) for the resource's name
     * exclusivity, its yolo:app tag, and which sync tier writes it.
     */
    public function scope(): Scope;

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
