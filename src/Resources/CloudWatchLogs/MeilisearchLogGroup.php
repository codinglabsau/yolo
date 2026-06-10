<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Log group for the shared Meilisearch task. Retention is hardcoded (30 days)
 * — env-shared config carries no per-app manifest knobs.
 */
class MeilisearchLogGroup implements Resource
{
    use ResolvesTags;

    public const RETENTION_IN_DAYS = 30;

    public function name(): string
    {
        return sprintf('/yolo/%s', $this->keyedName('meilisearch'));
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            CloudWatchLogs::logGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return CloudWatchLogs::logGroup($this->name())['arn'];
    }

    public function create(): void
    {
        Aws::cloudWatchLogs()->createLogGroup([
            'logGroupName' => $this->name(),
            'tags' => Aws::tags($this->tags(), wrap: 'tags', associative: true)['tags'],
        ]);

        Aws::cloudWatchLogs()->putRetentionPolicy([
            'logGroupName' => $this->name(),
            'retentionInDays' => self::RETENTION_IN_DAYS,
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags(), $apply);
    }
}
