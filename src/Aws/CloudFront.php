<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Aws\Exception\AwsException;
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

    public static function responseHeadersPolicyByName(string $name): array
    {
        $marker = null;

        do {
            $list = Aws::cloudFront()->listResponseHeadersPolicies([
                'Type' => 'custom',
                ...$marker ? ['Marker' => $marker] : [],
            ])['ResponseHeadersPolicyList'];

            foreach ($list['Items'] ?? [] as $item) {
                if (($item['ResponseHeadersPolicy']['ResponseHeadersPolicyConfig']['Name'] ?? null) === $name) {
                    return $item['ResponseHeadersPolicy'];
                }
            }

            $marker = ($list['IsTruncated'] ?? false) ? $list['NextMarker'] : null;
        } while ($marker);

        throw new ResourceDoesNotExistException("Could not find response headers policy with name $name");
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

    /**
     * Whether the distribution's real-time additional metrics (cache hit rate,
     * origin latency, error rate by status) are switched on. A distribution
     * with no monitoring subscription answers `NoSuchMonitoringSubscription` —
     * read as "off"; any other error is re-thrown rather than masked as off.
     */
    public static function additionalMetricsEnabled(string $distributionId): bool
    {
        try {
            $response = Aws::cloudFront()->getMonitoringSubscription(['DistributionId' => $distributionId]);
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchMonitoringSubscription') {
                return false;
            }

            throw $exception;
        }

        return Arr::get($response->toArray(), 'MonitoringSubscription.RealtimeMetricsSubscriptionConfig.RealtimeMetricsSubscriptionStatus') === 'Enabled';
    }

    /** Switch the distribution's real-time additional metrics on. */
    public static function enableAdditionalMetrics(string $distributionId): void
    {
        Aws::cloudFront()->createMonitoringSubscription([
            'DistributionId' => $distributionId,
            'MonitoringSubscription' => [
                'RealtimeMetricsSubscriptionConfig' => [
                    'RealtimeMetricsSubscriptionStatus' => 'Enabled',
                ],
            ],
        ]);
    }
}
