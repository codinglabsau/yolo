<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class SyncTaskLogGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $name = static::logGroupName();
        $retention = Helpers::validateCloudWatchLogRetention(
            Manifest::get('tasks.web.log-retention', 30),
            'tasks.web.log-retention',
        );

        $existing = static::findLogGroup($name);

        if ($existing !== null) {
            if (($existing['retentionInDays'] ?? null) === $retention) {
                return StepResult::SYNCED;
            }

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::cloudWatchLogs()->putRetentionPolicy([
                'logGroupName' => $name,
                'retentionInDays' => $retention,
            ]);

            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        Aws::cloudWatchLogs()->createLogGroup([
            'logGroupName' => $name,
            'tags' => Aws::tags(['Name' => $name], wrap: 'tags', associative: true)['tags'],
        ]);

        Aws::cloudWatchLogs()->putRetentionPolicy([
            'logGroupName' => $name,
            'retentionInDays' => $retention,
        ]);

        return StepResult::CREATED;
    }

    public static function logGroupName(): string
    {
        return Manifest::get(
            'tasks.web.log-group',
            sprintf('/yolo/%s', Helpers::keyedResourceName(exclusive: true))
        );
    }

    protected static function findLogGroup(string $name): ?array
    {
        try {
            $groups = Aws::cloudWatchLogs()->describeLogGroups([
                'logGroupNamePrefix' => $name,
            ])['logGroups'];
        } catch (AwsException) {
            return null;
        }

        foreach ($groups as $group) {
            if ($group['logGroupName'] === $name) {
                return $group;
            }
        }

        return null;
    }
}
