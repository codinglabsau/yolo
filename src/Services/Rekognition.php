<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;

class Rekognition extends ServiceDefinition
{
    public function service(): Service
    {
        return Service::REKOGNITION;
    }

    public function description(): string
    {
        return 'Image & video analysis (Amazon Rekognition)';
    }

    public function envBacked(): bool
    {
        return false;
    }

    /** The detection APIs are resource-less, so the grant is service-wide; S3 access rides the app's bucket statements. */
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

        // Metrics are dimensioned per Operation and the app picks its APIs at runtime — SEARCH avoids a hardcoded list that drifts.
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
