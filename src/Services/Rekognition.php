<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;

/**
 * Amazon Rekognition (image/video analysis). App-side only: a task-role grant
 * on a pure pay-per-call API — no infrastructure at either tier.
 */
class Rekognition extends ServiceDefinition
{
    public function service(): Service
    {
        return Service::REKOGNITION;
    }

    public function envBacked(): bool
    {
        return false;
    }

    /**
     * The detection APIs are resource-less — they operate on request payloads
     * or S3 objects read with the caller's own credentials, so the grant is
     * service-wide and S3 access rides the app's bucket statements.
     */
    public function taskRoleStatements(): array
    {
        return [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['rekognition:*'],
            ],
        ];
    }

    #[\Override]
    public function dashboardContext(): array
    {
        return [
            'rekognition' => Manifest::usesService(Service::REKOGNITION),
        ];
    }

    #[\Override]
    public function servicesWidgets(array $context): array
    {
        if (! $context['rekognition']) {
            return [];
        }

        // Rekognition metrics are dimensioned per Operation and the app
        // decides at runtime which APIs it calls — SEARCH charts whatever
        // operations actually report, no hardcoded list to drift.
        $search = fn (string $metric, string $label): array => [[
            'expression' => sprintf('SEARCH(\'{AWS/Rekognition,Operation} MetricName="%s"\', \'Sum\', 300)', $metric),
            'label' => $label,
            'region' => $context['region'],
        ]];

        return [[
            'title' => 'Rekognition requests (account, by operation)',
            'region' => $context['region'],
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 300,
            'stat' => 'Sum',
            'metrics' => [
                $search('SuccessfulRequestCount', 'Successful'),
                $search('ThrottledCount', 'Throttled'),
                $search('UserErrorCount', 'User errors'),
                $search('ServerErrorCount', 'Server errors'),
            ],
        ]];
    }
}
