<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TaskLogGroup implements Resource
{
    protected ?array $cached = null;

    public function name(): string
    {
        return Manifest::get(
            'tasks.web.log-group',
            sprintf('/yolo/%s', Helpers::keyedResourceName(exclusive: true))
        );
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        return $this->live() !== null;
    }

    public function arn(): string
    {
        return $this->live()['arn'];
    }

    /**
     * Memoised describe — Resource methods (exists, arn, currentRetentionInDays)
     * each call into this; one SDK round-trip per Resource instance lifetime.
     */
    protected function live(): ?array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        try {
            return $this->cached = CloudWatchLogs::logGroup($this->name());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
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

        $this->cached = null;
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags());
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
        return $this->live()['retentionInDays'] ?? null;
    }
}
