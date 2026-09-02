<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

class ResourceGroupsTaggingApi
{
    /**
     * The Tagging API is regional, and global-service resources (IAM, CloudFront,
     * Route 53) are only ever returned by a us-east-1 query — without the second
     * pass the audit silently omits YOLO's entire global footprint. The ARN
     * dedupe collapses the overlap when the environment itself is in us-east-1.
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
