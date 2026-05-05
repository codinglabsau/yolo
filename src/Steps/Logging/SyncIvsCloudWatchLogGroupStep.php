<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsCloudWatchLogGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsEnabled()) {
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
                    ...Aws::tags(['Name' => $name], wrap: 'tags', associative: true),
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
        // /aws/ivs/ prefix follows AWS convention for service-specific log groups
        return '/aws/ivs/' . Helpers::keyedResourceName();
    }

    private static function putResourcePolicy(string $logGroupName, string $logGroupArn): void
    {
        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();

        Aws::cloudWatchLogs()->putResourcePolicy([
            'policyName' => Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false),
            'policyDocument' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Sid' => 'EventBridgeToCloudWatchLogs',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'events.amazonaws.com'],
                    'Action' => ['logs:CreateLogStream', 'logs:PutLogEvents'],
                    'Resource' => "arn:aws:logs:{$region}:{$accountId}:log-group:/aws/ivs/*",
                ]],
            ]),
        ]);
    }
}
