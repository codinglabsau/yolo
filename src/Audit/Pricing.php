<?php

namespace Codinglabs\Yolo\Audit;

/**
 * Rough monthly cost estimates for the legacy audit. These are a guide for
 * "what is the idle alpha stack costing me?", not a billing source — figures are
 * approximate on-demand Linux list prices and an unlisted region or instance
 * type returns null (rendered as "?") rather than a misleading zero.
 */
class Pricing
{
    public const HOURS_PER_MONTH = 730;

    /**
     * Approximate on-demand Linux $/hour, keyed by region then instance type.
     *
     * @var array<string, array<string, float>>
     */
    private const EC2_HOURLY = [
        'ap-southeast-2' => [
            't2.micro' => 0.0146, 't2.small' => 0.0292, 't2.medium' => 0.0584, 't2.large' => 0.1168, 't2.xlarge' => 0.2336,
            't3.nano' => 0.0066, 't3.micro' => 0.0132, 't3.small' => 0.0264, 't3.medium' => 0.0528, 't3.large' => 0.1056, 't3.xlarge' => 0.2112, 't3.2xlarge' => 0.4224,
            't3a.nano' => 0.0059, 't3a.micro' => 0.0119, 't3a.small' => 0.0238, 't3a.medium' => 0.0475, 't3a.large' => 0.095, 't3a.xlarge' => 0.1901, 't3a.2xlarge' => 0.3802,
            't4g.nano' => 0.0053, 't4g.micro' => 0.0106, 't4g.small' => 0.0212, 't4g.medium' => 0.0424, 't4g.large' => 0.0848, 't4g.xlarge' => 0.1696, 't4g.2xlarge' => 0.3392,
            'm5.large' => 0.12, 'm5.xlarge' => 0.24, 'm5.2xlarge' => 0.48, 'm5.4xlarge' => 0.96,
            'm6i.large' => 0.12, 'm6i.xlarge' => 0.24, 'm6i.2xlarge' => 0.48,
            'c5.large' => 0.111, 'c5.xlarge' => 0.222, 'c5.2xlarge' => 0.444,
            'c6i.large' => 0.1105, 'c6i.xlarge' => 0.221,
            'r5.large' => 0.151, 'r5.xlarge' => 0.302,
        ],
    ];

    /**
     * Approximate Application Load Balancer hourly baseline $/hour, by region
     * (the fixed per-ALB charge, before LCU usage).
     *
     * @var array<string, float>
     */
    private const ALB_HOURLY = [
        'ap-southeast-2' => 0.0252,
    ];

    public static function ec2Monthly(string $instanceType, ?string $region): ?float
    {
        $hourly = self::EC2_HOURLY[$region][$instanceType] ?? null;

        return $hourly === null ? null : round($hourly * self::HOURS_PER_MONTH, 2);
    }

    public static function loadBalancerMonthly(?string $region): ?float
    {
        $hourly = self::ALB_HOURLY[$region] ?? null;

        return $hourly === null ? null : round($hourly * self::HOURS_PER_MONTH, 2);
    }
}
