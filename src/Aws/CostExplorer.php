<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;

/**
 * Cost Explorer is a global service — its API only lives in us-east-1 (the client
 * is pinned there). It attributes cost by a tag only once that tag is activated
 * as a cost-allocation tag in the Billing console, and its data lags ~24h; until
 * then a tag-filtered query returns nothing, so every read degrades to null.
 */
class CostExplorer
{
    public static function monthToDateByApp(string $app): ?float
    {
        return static::monthToDateByTag('yolo:app', $app);
    }

    public static function monthToDateByEnvironment(string $environment): ?float
    {
        return static::monthToDateByTag('yolo:environment', $environment);
    }

    protected static function monthToDateByTag(string $key, string $value): ?float
    {
        try {
            $results = Aws::costExplorer()->getCostAndUsage([
                'TimePeriod' => static::monthToDate(),
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'Filter' => ['Tags' => ['Key' => $key, 'Values' => [$value]]],
            ])['ResultsByTime'] ?? [];
        } catch (AwsException) {
            return null;
        }

        $amount = $results[0]['Total']['UnblendedCost']['Amount'] ?? null;

        return $amount === null ? null : (float) $amount;
    }

    /**
     * End is exclusive, so tomorrow includes today — and on the 1st this is a
     * one-day span, never the empty start==end range CE rejects.
     *
     * @return array{Start: string, End: string}
     */
    protected static function monthToDate(): array
    {
        return [
            'Start' => gmdate('Y-m-01'),
            'End' => gmdate('Y-m-d', time() + 86400),
        ];
    }
}
