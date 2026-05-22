<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * CloudFront has no name-based lookup — distributions are identified by an
 * opaque ID. YOLO stamps the keyed resource name into the distribution's
 * Comment (and the OAC's Name) and finds them by scanning the account list.
 */
class CloudFront
{
    public static function distributionByComment(string $comment): array
    {
        $marker = null;

        do {
            $list = Aws::cloudFront()->listDistributions([
                ...$marker ? ['Marker' => $marker] : [],
            ])['DistributionList'];

            foreach ($list['Items'] ?? [] as $distribution) {
                if (($distribution['Comment'] ?? null) === $comment) {
                    return $distribution;
                }
            }

            $marker = ($list['IsTruncated'] ?? false) ? $list['NextMarker'] : null;
        } while ($marker);

        throw new ResourceDoesNotExistException("Could not find CloudFront distribution with comment $comment");
    }

    public static function originAccessControlByName(string $name): array
    {
        $marker = null;

        do {
            $list = Aws::cloudFront()->listOriginAccessControls([
                ...$marker ? ['Marker' => $marker] : [],
            ])['OriginAccessControlList'];

            foreach ($list['Items'] ?? [] as $oac) {
                if (($oac['Name'] ?? null) === $name) {
                    return $oac;
                }
            }

            $marker = ($list['IsTruncated'] ?? false) ? $list['NextMarker'] : null;
        } while ($marker);

        throw new ResourceDoesNotExistException("Could not find Origin Access Control with name $name");
    }
}
