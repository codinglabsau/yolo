<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Aws\Rds;
use Aws\Command as AwsCommand;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

describe('target', function (): void {
    it('returns null when the manifest declares no database', function (): void {
        $rds = [];
        bindMockRdsClient([], $rds);

        expect(Rds::target())->toBeNull()
            ->and($rds)->toBeEmpty();
    });

    it('classifies a name that describes as a cluster as the Aurora cluster', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => [['DBClusterIdentifier' => 'my-cluster']]]),
        ], $rds);

        expect(Rds::target())->toBe(['identifier' => 'my-cluster', 'cluster' => true])
            ->and(collect($rds)->pluck('name'))->not->toContain('DescribeDBInstances');
    });

    it('falls through to a plain instance when no cluster matches the name', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('not found', new AwsCommand('DescribeDBClusters'), ['code' => 'DBClusterNotFoundFault']),
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'my-db']]]),
        ], $rds);

        expect(Rds::target())->toBe(['identifier' => 'my-db', 'cluster' => false]);
    });

    it('tolerates an empty cluster describe as well as the not-found fault', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => []]),
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'my-db']]]),
        ], $rds);

        expect(Rds::target())->toBe(['identifier' => 'my-db', 'cluster' => false]);
    });

    it('throws when the declared name matches no cluster and no instance — a manifest error, not an empty panel', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'typo-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('not found', new AwsCommand('DescribeDBClusters'), ['code' => 'DBClusterNotFoundFault']),
            'DescribeDBInstances' => new RdsException('not found', new AwsCommand('DescribeDBInstances'), ['code' => 'DBInstanceNotFound']),
        ], $rds);

        expect(fn (): ?array => Rds::target())->toThrow(ResourceDoesNotExistException::class, 'typo-db');
    });

    it('rethrows a non-not-found failure — a denied describe must not silently misclassify', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('denied', new AwsCommand('DescribeDBClusters'), ['code' => 'AccessDenied']),
        ], $rds);

        expect(fn (): ?array => Rds::target())->toThrow(RdsException::class);
    });

    it('memoises the classification — the kind of a name is a stable fact', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => [['DBClusterIdentifier' => 'my-cluster']]]),
        ], $rds);

        Rds::target();
        Rds::target();

        expect(collect($rds)->where('name', 'DescribeDBClusters'))->toHaveCount(1);
    });
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
