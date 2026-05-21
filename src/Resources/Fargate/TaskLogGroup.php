<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Resources\Resource;

class TaskLogGroup implements Resource
{
    protected ?array $cachedGroup = null;

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
        return $this->find() !== null;
    }

    public function arn(): string
    {
        return $this->find()['arn'];
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

        $this->cachedGroup = null;
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
        return $this->find()['retentionInDays'] ?? null;
    }

    protected function find(): ?array
    {
        if ($this->cachedGroup !== null) {
            return $this->cachedGroup;
        }

        try {
            $groups = Aws::cloudWatchLogs()->describeLogGroups([
                'logGroupNamePrefix' => $this->name(),
            ])['logGroups'];
        } catch (AwsException) {
            return null;
        }

        foreach ($groups as $group) {
            if ($group['logGroupName'] === $this->name()) {
                return $this->cachedGroup = $group;
            }
        }

        return null;
    }
}
