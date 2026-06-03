<?php

namespace Codinglabs\Yolo\Audit;

/**
 * A parsed AWS ARN. Only the pieces the audit needs to classify a resource —
 * service, region, and the type/id split of the resource segment.
 */
class Arn
{
    private function __construct(
        public readonly string $service,
        public readonly string $region,
        public readonly string $accountId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly string $value,
    ) {}

    public static function parse(string $arn): ?self
    {
        // arn:partition:service:region:account-id:resource — the resource segment
        // itself can contain ':' (CodeDeploy) and '/' (ELBv2), so cap the split.
        $parts = explode(':', $arn, 6);

        if (count($parts) < 6 || $parts[0] !== 'arn') {
            return null;
        }

        [, , $service, $region, $accountId, $resource] = $parts;

        // The resource segment is `type/id`, `type:id`, or a bare id. Split on the
        // first '/' or ':' to peel off the type; a bare id has no type.
        $split = preg_split('#[/:]#', $resource, 2);

        return new self(
            service: $service,
            region: $region,
            accountId: $accountId,
            resourceType: count($split) === 2 ? $split[0] : '',
            resourceId: count($split) === 2 ? $split[1] : $resource,
            value: $arn,
        );
    }
}
