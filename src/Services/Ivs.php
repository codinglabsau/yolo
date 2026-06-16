<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/**
 * Amazon IVS (live streaming). The app drives IVS itself at runtime; the
 * env-shared half is the event-logging pipeline (EventBridge rule + target +
 * log group) — env-shared because the `aws.ivs` event stream is account-wide,
 * so per-app pipelines would each capture every other app's events.
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

    /**
     * The app drives IVS itself at runtime — channels, stream keys and streams
     * are created on demand, so there are no stable resource ARNs to scope to
     * and the grant is service-wide. The env-shared event-logging pipeline is
     * the environment manifest's concern, not this role's.
     */
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
