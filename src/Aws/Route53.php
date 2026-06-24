<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Route53
{
    public static function hostedZone(string $domain): array
    {
        return static::findHostedZone($domain)
            ?? throw ResourceDoesNotExistException::make("Could not find hosted zone for domain $domain")
                ->suggest('sync:compute');
    }

    /**
     * The hosted zone for a domain, or null when none exists — the non-throwing
     * counterpart to {@see hostedZone()}, used by apex derivation to probe a
     * candidate suffix without exploding on a miss.
     *
     * @return array<string, mixed>|null
     */
    public static function findHostedZone(string $domain): ?array
    {
        foreach (Aws::route53()->listHostedZones()['HostedZones'] ?? [] as $hostedZone) {
            if ($hostedZone['Name'] === "$domain.") {
                return $hostedZone;
            }
        }

        return null;
    }

    /**
     * Every hosted-zone name in the account, trailing dot stripped
     * (`example.com.` → `example.com`), so apex derivation can match a domain's
     * label-suffixes against the live zones in a single list call.
     *
     * @return array<int, string>
     */
    public static function hostedZoneNames(): array
    {
        return array_map(
            fn (array $hostedZone): string => rtrim((string) $hostedZone['Name'], '.'),
            Aws::route53()->listHostedZones()['HostedZones'] ?? [],
        );
    }
}
