<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/**
 * The event-logging pipeline is env-shared because the `aws.ivs` event stream
 * is account-wide — per-app pipelines would each capture every other app's events.
 */
class Ivs extends ServiceDefinition
{
    public function service(): Service
    {
        return Service::IVS;
    }

    public function description(): string
    {
        return 'Live, low-latency video streaming (Amazon IVS)';
    }

    #[\Override]
    public function implications(): string
    {
        return 'IVS provisions a shared event-logging pipeline for the environment (an EventBridge rule + CloudWatch log group) — negligible cost; the app drives IVS channels itself at runtime.';
    }

    public function envBacked(): bool
    {
        return true;
    }

    /** Channels, stream keys and streams are created on demand — no stable ARNs to scope to, so the grant is service-wide. */
    public function taskRoleStatements(): array
    {
        return [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['ivs:*'],
            ],
        ];
    }

    #[\Override]
    public function environmentSteps(): array
    {
        return [
            Steps\Sync\Environment\SyncIvsCloudWatchLogGroupStep::class,
            Steps\Sync\Environment\SyncIvsEventBridgeRuleStep::class,
            Steps\Sync\Environment\SyncIvsEventBridgeTargetStep::class,
        ];
    }

    /** The rule delete removes its own targets, so the target step needs no teardown. */
    #[\Override]
    public function teardownEnvironmentSteps(): array
    {
        return [
            Steps\Sync\Environment\SyncIvsEventBridgeRuleStep::class,
            Steps\Sync\Environment\SyncIvsCloudWatchLogGroupStep::class,
        ];
    }

    #[\Override]
    public function dashboardContext(): array
    {
        return [
            'ivsLogGroup' => Manifest::usesService(Service::IVS) ? (new IvsLogGroup())->name() : null,
        ];
    }

    #[\Override]
    public function logPanels(array $context): array
    {
        return ['IVS logs' => $context['ivsLogGroup']];
    }
}
