<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TaskLogGroup implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get(
            'tasks.web.log-group',
            sprintf('/yolo/%s', $this->keyedName())
        );
    }

    public function scope(): Scope
    {
        return Scope::App;
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
            'retentionInDays' => $this->retentionInDays(),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Retention is a separate AWS concept from tags. The step calls this when
     * it detects drift between the manifest's expected retention and the live
     * log group's retentionInDays.
     */
    public function synchroniseRetention(): void
    {
        Aws::cloudWatchLogs()->putRetentionPolicy([
            'logGroupName' => $this->name(),
            'retentionInDays' => $this->retentionInDays(),
        ]);
    }

    public function retentionInDays(): int
    {
        return Helpers::validateCloudWatchLogRetention(
            Manifest::get('tasks.web.log-retention', 30),
            'tasks.web.log-retention',
        );
    }

    public function currentRetentionInDays(): ?int
    {
        try {
            return CloudWatchLogs::logGroup($this->name())['retentionInDays'] ?? null;
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
