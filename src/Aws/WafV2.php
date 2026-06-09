<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Thin wrapper over the WAFv2 control plane. Every YOLO WAF resource is
 * `REGIONAL` (the scope that protects an Application Load Balancer — `CLOUDFRONT`
 * scope lives only in us-east-1 and isn't used here), so the scope is baked in
 * rather than threaded through every call.
 *
 * WAFv2 has no get-by-name: you list the summaries (which already carry the Id,
 * ARN and LockToken needed for reads, updates and tagging) and match on Name.
 * Both lookups page through NextMarker and throw when the name is absent, so
 * callers get the same exists()/arn() shape every other `src/Aws/*` wrapper has.
 */
class WafV2
{
    public const SCOPE = 'REGIONAL';

    /**
     * The WebACL summary {Name, Id, ARN, LockToken} for the given name.
     *
     * @return array<string, string>
     */
    public static function webAcl(string $name): array
    {
        return static::findByName('listWebACLs', 'WebACLs', $name)
            ?? throw new ResourceDoesNotExistException("Could not find WAF web ACL $name");
    }

    /**
     * The IPSet summary {Name, Id, ARN, LockToken} for the given name.
     *
     * @return array<string, string>
     */
    public static function ipSet(string $name): array
    {
        return static::findByName('listIPSets', 'IPSets', $name)
            ?? throw new ResourceDoesNotExistException("Could not find WAF IP set $name");
    }

    /**
     * Page through a WAFv2 list operation and return the first summary whose
     * Name matches, or null. WAFv2 list pages are capped, so NextMarker is
     * followed to completion.
     *
     * @return array<string, string>|null
     */
    protected static function findByName(string $operation, string $key, string $name): ?array
    {
        $marker = null;

        do {
            $response = Aws::wafV2()->{$operation}(array_filter([
                'Scope' => static::SCOPE,
                'NextMarker' => $marker,
            ]));

            foreach ($response[$key] ?? [] as $summary) {
                if ($summary['Name'] === $name) {
                    return $summary;
                }
            }

            $marker = $response['NextMarker'] ?? null;
        } while ($marker !== null && ($response[$key] ?? []) !== []);

        return null;
    }
}
