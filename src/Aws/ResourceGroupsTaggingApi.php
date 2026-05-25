<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;

class ResourceGroupsTaggingApi
{
    /**
     * Every resource carrying the given tag filters, across all pages.
     *
     * The Tagging API caps each page and hands back a PaginationToken until the
     * last page returns an empty one — we follow it to the end so callers get the
     * complete inventory in one list.
     *
     * @param  array<int, array{Key: string, Values?: array<int, string>}>  $tagFilters
     * @return array<int, array{ResourceARN: string, Tags: array<int, array{Key: string, Value: string}>}>
     */
    public static function getResources(array $tagFilters): array
    {
        $resources = [];
        $token = '';

        do {
            $result = Aws::resourceGroupsTaggingApi()->getResources(array_filter([
                'TagFilters' => $tagFilters,
                'PaginationToken' => $token,
            ]));

            $resources = [...$resources, ...$result['ResourceTagMappingList']];

            $token = $result['PaginationToken'] ?? '';
        } while ($token !== '');

        return $resources;
    }
}
