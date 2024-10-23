<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesRoute53
{
    public static function hostedZone(string $domain): array
    {
        $hostedZones = Aws::route53()->listHostedZones();

        foreach ($hostedZones['HostedZones'] as $hostedZone) {
            if ($hostedZone['Name'] === "$domain.") {
                return $hostedZone;
            }
        }

        throw new ResourceDoesNotExistException("Could not find Hosted Zone for domain $domain");
    }
}
