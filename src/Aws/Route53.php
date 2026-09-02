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
     * Trailing dot stripped (`example.com.` → `example.com`).
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
