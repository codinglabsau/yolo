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
    public function name(): string
    {
        return Manifest::get(
            'tasks.web.log-group',
            sprintf('/yolo/%s', Helpers::keyedResourceName(exclusive: true))
        );
    }

    public function tags(): array
    {
        return ['Name' => $this->name(), 'yolo:app' => Manifest::name()];
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
        try {
            return CloudWatchLogs::logGroup($this->name())['retentionInDays'] ?? null;
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
