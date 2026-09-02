<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Env-shared because the event stream is: `source: aws.ivs` matches every IVS event
 * in the account/region and channels are created at runtime, so per-app pipelines
 * would each capture every other app's events. `/aws/ivs/` follows the AWS
 * convention for service log groups.
 */
class IvsLogGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return '/aws/ivs/' . $this->keyedName();
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
        return sprintf(
            'arn:aws:logs:%s:%s:log-group:%s',
            Manifest::get('region'),
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

    /** Also removes the account-level EventBridge resource policy only this pipeline uses. */
    public function delete(): void
    {
        Aws::cloudWatchLogs()->deleteLogGroup([
            'logGroupName' => $this->name(),
        ]);

        if (CloudWatchLogs::resourcePolicy($this->eventBridgePolicyName()) !== null) {
            Aws::cloudWatchLogs()->deleteResourcePolicy([
                'policyName' => $this->eventBridgePolicyName(),
            ]);
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags(), $apply);
    }

    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcileRetention($apply),
            ...$this->reconcileEventBridgeResourcePolicy($apply),
        ];
    }

    /** Hardcoded — service opt-ins are bare capability names with no per-app knobs. */
    public function retentionDays(): int
    {
        return 14;
    }

    /**
     * @return array<int, Change>
     */
    protected function reconcileRetention(bool $apply): array
    {
        $logGroup = CloudWatchLogs::logGroup($this->name());
        $current = $logGroup['retentionInDays'] ?? null;

        if ($current === $this->retentionDays()) {
            return [];
        }

        if ($apply) {
            Aws::cloudWatchLogs()->putRetentionPolicy([
                'logGroupName' => $this->name(),
                'retentionInDays' => $this->retentionDays(),
            ]);
        }

        return [Change::make('retention-days', $current, $this->retentionDays())];
    }

    /**
     * @return array<int, Change>
     */
    protected function reconcileEventBridgeResourcePolicy(bool $apply): array
    {
        $current = CloudWatchLogs::resourcePolicy($this->eventBridgePolicyName());

        if (Helpers::documentsEqual($current, $this->eventBridgePolicyDocument())) {
            return [];
        }

        if ($apply) {
            Aws::cloudWatchLogs()->putResourcePolicy([
                'policyName' => $this->eventBridgePolicyName(),
                'policyDocument' => json_encode($this->eventBridgePolicyDocument()),
            ]);
        }

        return [Change::make('eventbridge-resource-policy', $current === null ? null : 'present', 'events.amazonaws.com → /aws/ivs/*')];
    }

    protected function eventBridgePolicyName(): string
    {
        return Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventBridgePolicyDocument(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [[
                'Sid' => 'EventBridgeToCloudWatchLogs',
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'events.amazonaws.com'],
                'Action' => ['logs:CreateLogStream', 'logs:PutLogEvents'],
                'Resource' => sprintf(
                    'arn:aws:logs:%s:%s:log-group:/aws/ivs/*',
                    Manifest::get('region'),
                    Aws::accountId(),
                ),
            ]],
        ];
    }
}
