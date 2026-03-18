<?php

namespace Codinglabs\Yolo\Steps\Ivs;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCloudWatchLogGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.ivs')) {
            return StepResult::SKIPPED;
        }

        $name = self::logGroupName();

        try {
            AwsResources::logGroup($name);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::cloudWatchLogs()->createLogGroup([
                    'logGroupName' => $name,
                    'tags' => Aws::tags([
                        'Name' => $name,
                    ], associative: true)['Tags'],
                ]);

                Aws::cloudWatchLogs()->putRetentionPolicy([
                    'logGroupName' => $name,
                    'retentionInDays' => Manifest::get('aws.ivs.log-retention-days', 14),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    public static function logGroupName(): string
    {
        return '/aws/ivs/' . Helpers::keyedResourceName('live-events');
    }
}
