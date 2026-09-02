<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Desired-state definition of one AWS resource. Steps decide WHEN to create or
 * sync; the resource decides WHAT it looks like.
 */
interface Resource
{
    public function name(): string;

    public function scope(): Scope;

    /**
     * Resource-specific tags only; `Aws::expectedTags()` adds the
     * `yolo:environment` baseline at write time.
     *
     * @return array<string, string>
     */
    public function tags(): array;

    public function exists(): bool;

    /**
     * @throws ResourceDoesNotExistException
     * @throws AwsException when the live lookup itself fails, e.g. denied under a read tier
     */
    public function arn(): string;

    public function create(): void;

    /**
     * Additive: writes the missing tags when `$apply`, and returns them either
     * way so the plan pass can record them as changes.
     *
     * @return array<string, string> missing tag keys → expected values
     */
    public function synchroniseTags(bool $apply): array;
}
