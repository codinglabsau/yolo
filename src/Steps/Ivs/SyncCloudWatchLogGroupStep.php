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
        if (! Manifest::has('aws.ivs')) {
            return StepResult::SKIPPED;
        }

        $name = self::logGroupName();

        $retentionDays = Manifest::get('aws.ivs.log-retention-days', 14);

        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();
        $logGroupArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$name}";

        try {
            $logGroup = AwsResources::logGroup($name);

            if (($logGroup['retentionInDays'] ?? null) !== $retentionDays) {
                if (! Arr::get($options, 'dry-run')) {
                    Aws::cloudWatchLogs()->putRetentionPolicy([
                        'logGroupName' => $name,
                        'retentionInDays' => $retentionDays,
                    ]);

                    self::putResourcePolicy($name, $logGroupArn);

                    return StepResult::SYNCED;
                }

                return StepResult::WOULD_SYNC;
            }

            if (! Arr::get($options, 'dry-run')) {
                self::putResourcePolicy($name, $logGroupArn);
            }

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
                    'retentionInDays' => $retentionDays,
                ]);

                self::putResourcePolicy($name, $logGroupArn);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    public static function logGroupName(): string
    {
        return '/aws/ivs/' . Helpers::keyedResourceName('live-events');
    }

    private static function putResourcePolicy(string $logGroupName, string $logGroupArn): void
    {
        Aws::cloudWatchLogs()->putResourcePolicy([
            'policyName' => Helpers::keyedResourceName('ivs-eventbridge-policy'),
            'policyDocument' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Sid' => 'EventBridgeToCloudWatchLogs',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'events.amazonaws.com'],
                    'Action' => ['logs:CreateLogStream', 'logs:PutLogEvents'],
                    'Resource' => $logGroupArn . ':*',
                ]],
            ]),
        ]);
    }
}
