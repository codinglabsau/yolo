<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
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

    /**
     * Hardcoded — IVS events are debugging telemetry, and service opt-ins are
     * bare capability names (`services: [ivs]`) with no per-app knobs.
     */
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
     * Grant EventBridge permission to deliver IVS events into any /aws/ivs/ log
     * group. Diffs the live resource policy against the desired document first, so
     * a clean sync makes no write and a dry-run reports the change.
     *
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
