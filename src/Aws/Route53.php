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
}
