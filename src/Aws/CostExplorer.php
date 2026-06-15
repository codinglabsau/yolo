<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;

/**
 * Reads month-to-date spend from AWS Cost Explorer, attributed per app via the
 * `yolo:app` tag every App-scope resource carries.
 *
 * Cost Explorer is a **global** service — its API only lives in us-east-1 (the
 * client is pinned there). It attributes cost by a tag only once that tag is
 * activated as a **cost-allocation tag** in the Billing console, and its data
 * lags ~24h; until then a tag-filtered query returns nothing. So every read here
 * degrades to null rather than throwing, and `yolo status:budget` renders "—".
 */
class CostExplorer
{
    /**
     * Month-to-date unblended spend (USD) attributed to one app via its
     * `yolo:app` cost-allocation tag, or null when Cost Explorer has no data
     * (tag not activated yet, or no spend) or the read fails.
     */
    public static function monthToDateByApp(string $app): ?float
    {
        try {
            $results = Aws::costExplorer()->getCostAndUsage([
                'TimePeriod' => static::monthToDate(),
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'Filter' => ['Tags' => ['Key' => 'yolo:app', 'Values' => [$app]]],
            ])['ResultsByTime'] ?? [];
        } catch (AwsException) {
            return null;
        }

        $amount = $results[0]['Total']['UnblendedCost']['Amount'] ?? null;

        return $amount === null ? null : (float) $amount;
    }

    /**
     * The current calendar-month-to-date window for Cost Explorer: first of the
     * month → tomorrow (the End is exclusive, so tomorrow includes today). On the
     * 1st this is a one-day span, never an empty start==end range CE rejects.
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
