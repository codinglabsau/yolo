<?php

use Codinglabs\Yolo\Audit\Pricing;

it('estimates monthly EC2 cost as hourly list price times 730 hours', function () {
    // t3.medium is 0.0528/hr in ap-southeast-2 → 0.0528 * 730 = 38.544 → 38.54
    expect(Pricing::ec2Monthly('t3.medium', 'ap-southeast-2'))->toBe(38.54);
    expect(Pricing::ec2Monthly('t3.large', 'ap-southeast-2'))->toBe(77.09);
});

it('returns null for an unknown instance type or region', function () {
    expect(Pricing::ec2Monthly('z1d.metal', 'ap-southeast-2'))->toBeNull();
    expect(Pricing::ec2Monthly('t3.medium', 'eu-west-1'))->toBeNull();
    expect(Pricing::ec2Monthly('t3.medium', null))->toBeNull();
});

it('estimates the load balancer baseline monthly cost', function () {
    // 0.0252/hr * 730 = 18.396 → 18.40
    expect(Pricing::loadBalancerMonthly('ap-southeast-2'))->toBe(18.40);
});

it('returns null for a load balancer in an unpriced region', function () {
    expect(Pricing::loadBalancerMonthly('eu-west-1'))->toBeNull();
    expect(Pricing::loadBalancerMonthly(null))->toBeNull();
});
