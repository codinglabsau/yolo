<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * CloudWatch log group that receives IVS state-change events (delivered by the
 * IvsEventBridgeRule). The /aws/ivs/ prefix follows the AWS convention for
 * service log groups. Retention and the EventBridge-delivery resource policy
 * are reconciled on every sync.
 */
class IvsLogGroup implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return '/aws/ivs/' . $this->keyedName();
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
        return sprintf(
            'arn:aws:logs:%s:%s:log-group:%s',
            Manifest::get('aws.region'),
            Aws::accountId(),
            $this->name(),
        );
    }

    public function create(): void
    {
        Aws::cloudWatchLogs()->createLogGroup([
            'logGroupName' => $this->name(),
            ...Aws::tags($this->tags(), wrap: 'tags', associative: true),
        ]);

        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags());
    }

    public function synchroniseConfiguration(): void
    {
        $this->reconcileRetention();
        $this->putEventBridgeResourcePolicy();
    }

    public function retentionDays(): int
    {
        return (int) Manifest::get('aws.ivs.log-retention-days', 14);
    }

    protected function reconcileRetention(): void
    {
        $logGroup = CloudWatchLogs::logGroup($this->name());

        if (($logGroup['retentionInDays'] ?? null) === $this->retentionDays()) {
            return;
        }

        Aws::cloudWatchLogs()->putRetentionPolicy([
            'logGroupName' => $this->name(),
            'retentionInDays' => $this->retentionDays(),
        ]);
    }

    /**
     * Grant EventBridge permission to deliver IVS events into any /aws/ivs/ log
     * group. Idempotent put, so it's safe to re-apply on every sync.
     */
    protected function putEventBridgeResourcePolicy(): void
    {
        Aws::cloudWatchLogs()->putResourcePolicy([
            'policyName' => Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false),
            'policyDocument' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Sid' => 'EventBridgeToCloudWatchLogs',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'events.amazonaws.com'],
                    'Action' => ['logs:CreateLogStream', 'logs:PutLogEvents'],
                    'Resource' => sprintf(
                        'arn:aws:logs:%s:%s:log-group:/aws/ivs/*',
                        Manifest::get('aws.region'),
                        Aws::accountId(),
                    ),
                ]],
            ]),
        ]);
    }
}
