<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Aws\Rds;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('returns only the databases whose subnet group sits in the given VPC', function (): void {
    $rds = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'in-vpc', 'DBSubnetGroup' => ['VpcId' => 'vpc-1']],
            ['DBInstanceIdentifier' => 'other-vpc', 'DBSubnetGroup' => ['VpcId' => 'vpc-2']],
            ['DBInstanceIdentifier' => 'classic-no-subnet-group'],
        ]]),
    ], $rds);

    // Only the instance in vpc-1 — a database in another VPC (or none) doesn't pin it.
    expect(Rds::instancesInVpc('vpc-1'))->toBe(['in-vpc']);
});

it('maps every instance with an endpoint to its address, across pages', function (): void {
    $rds = [];
    bindMockRdsClient([
        'DescribeDBInstances' => [
            new Result([
                'DBInstances' => [
                    ['DBInstanceIdentifier' => 'first-db', 'Endpoint' => ['Address' => 'first-db.abc.rds.amazonaws.com']],
                    ['DBInstanceIdentifier' => 'still-creating'],
                ],
                'Marker' => 'page-2',
            ]),
            new Result([
                'DBInstances' => [
                    ['DBInstanceIdentifier' => 'second-db', 'Endpoint' => ['Address' => 'second-db.abc.rds.amazonaws.com']],
                ],
            ]),
        ],
    ], $rds);

    expect(Rds::instanceEndpoints())->toBe([
        'first-db' => 'first-db.abc.rds.amazonaws.com',
        'second-db' => 'second-db.abc.rds.amazonaws.com',
    ]);

    expect(collect($rds)->where('name', 'DescribeDBInstances'))->toHaveCount(2);
});
