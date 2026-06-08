<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

class ResourceGroupsTaggingApi
{
    /**
     * Every resource carrying the given tag filters across the account, deduped
     * by ARN.
     *
     * The Tagging API is regional, and global-service resources — IAM roles and
     * policies, CloudFront distributions, Route 53 hosted zones — are only ever
     * returned by a us-east-1 query. So we run the filter twice: once against the
     * environment's own region (regional services) and once against us-east-1
     * (the global surface, plus any us-east-1-pinned resources like a CloudFront
     * ACM certificate), then merge. Without the second pass the audit silently
     * omits YOLO's entire global footprint. The two passes overlap only when the
     * environment itself lives in us-east-1, where the ARN dedupe collapses them.
     *
     * @param  array<int, array{Key: string, Values?: array<int, string>}>  $tagFilters
     * @return array<int, array{ResourceARN: string, Tags: array<int, array{Key: string, Value: string}>}>
     */
    public static function getResources(array $tagFilters): array
    {
        return collect([
            ...static::paginate($tagFilters, Aws::resourceGroupsTaggingApi()),
            ...static::paginate($tagFilters, Aws::resourceGroupsTaggingApiGlobal()),
        ])
            ->unique('ResourceARN')
            ->values()
            ->all();
    }

    /**
     * Follow the Tagging API's PaginationToken to the end against a single
     * client, so callers get the complete inventory for that region in one list.
     *
     * @param  array<int, array{Key: string, Values?: array<int, string>}>  $tagFilters
     * @return array<int, array{ResourceARN: string, Tags: array<int, array{Key: string, Value: string}>}>
     */
    protected static function paginate(array $tagFilters, ResourceGroupsTaggingAPIClient $client): array
    {
        $resources = [];
        $token = '';

        do {
            $result = $client->getResources(array_filter([
                'TagFilters' => $tagFilters,
                'PaginationToken' => $token,
            ]));

            $resources = [...$resources, ...$result['ResourceTagMappingList']];

            $token = $result['PaginationToken'] ?? '';
        } while ($token !== '');

        return $resources;
    }
}
