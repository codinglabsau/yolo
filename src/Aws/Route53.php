<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Route53
{
    public static function hostedZone(string $domain): array
    {
        foreach (Aws::route53()->listHostedZones()['HostedZones'] ?? [] as $hostedZone) {
            if ($hostedZone['Name'] === "$domain.") {
                return $hostedZone;
            }
        }

        throw ResourceDoesNotExistException::make("Could not find hosted zone for domain $domain")
            ->suggest('sync:compute');
    }

    /**
     * The record set with this exact name and type in the zone, or null. The
     * list is name-ordered, so seeking to the name and reading one item is an
     * exact-match probe, not a scan.
     *
     * @return array<string, mixed>|null
     */
    public static function recordSet(string $hostedZoneId, string $name, string $type): ?array
    {
        $records = Aws::route53()->listResourceRecordSets([
            'HostedZoneId' => $hostedZoneId,
            'StartRecordName' => $name,
            'StartRecordType' => $type,
            'MaxItems' => '1',
        ])['ResourceRecordSets'] ?? [];

        $record = $records[0] ?? null;

        if ($record !== null
            && rtrim((string) $record['Name'], '.') === rtrim($name, '.')
            && $record['Type'] === $type) {
            return $record;
        }

        return null;
    }
}
