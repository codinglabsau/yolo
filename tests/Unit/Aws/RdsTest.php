<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Aws\Rds;
use Aws\Command as AwsCommand;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
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

describe('port', function (): void {
    it('reads a plain instance\'s port off its endpoint', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => []]),
            'DescribeDBInstances' => new Result(['DBInstances' => [[
                'DBInstanceIdentifier' => 'my-db',
                'Engine' => 'postgres',
                'Endpoint' => ['Address' => 'my-db.abc.rds.amazonaws.com', 'Port' => 5432],
            ]]]),
        ], $rds);

        expect(Rds::port())->toBe(5432);
    });

    it('reads a cluster\'s port off the cluster record, where RDS carries it', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => [[
                'DBClusterIdentifier' => 'my-cluster',
                'Engine' => 'aurora-postgresql',
                'Port' => 5432,
            ]]]),
        ], $rds);

        expect(Rds::port())->toBe(5432);
    });

    it('honours a non-default port — the record is the source of truth, not the engine', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => []]),
            'DescribeDBInstances' => new Result(['DBInstances' => [[
                'DBInstanceIdentifier' => 'my-db',
                'Engine' => 'mysql',
                'Endpoint' => ['Address' => 'my-db.abc.rds.amazonaws.com', 'Port' => 3307],
            ]]]),
        ], $rds);

        expect(Rds::port())->toBe(3307);
    });

    it('is null without touching RDS when the manifest declares no database', function (): void {
        $rds = [];
        bindMockRdsClient([], $rds);

        // No port to authorise — the caller writes no ingress rule at all, which
        // is what lets a greenfield app provision the group before the database.
        expect(Rds::port())->toBeNull()
            ->and($rds)->toBeEmpty();
    });

    it('throws when the declared database resolves to nothing — a manifest error, not a guessed port', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'typo-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('not found', new AwsCommand('DescribeDBClusters'), ['code' => 'DBClusterNotFoundFault']),
            'DescribeDBInstances' => new RdsException('not found', new AwsCommand('DescribeDBInstances'), ['code' => 'DBInstanceNotFound']),
        ], $rds);

        expect(fn (): ?int => Rds::port())
            ->toThrow(ResourceDoesNotExistException::class, 'Create the database first');
    });

    it('is null when the describe is denied — a permissions gap must not read as a manifest error', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('denied', new AwsCommand('DescribeDBClusters'), ['code' => 'AccessDenied']),
        ], $rds);

        expect(Rds::port())->toBeNull();
    });

    it('is null when the instance reports no port yet — mid-creation, so nothing to authorise', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => []]),
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'my-db']]]),
        ], $rds);

        expect(Rds::port())->toBeNull();
    });

    it('memoises the port alongside the classification', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new Result(['DBClusters' => [['DBClusterIdentifier' => 'my-cluster', 'Port' => 5432]]]),
        ], $rds);

        Rds::port();
        Rds::port();

        // One describe to classify, one to read the port — never four.
        expect(collect($rds)->where('name', 'DescribeDBClusters'))->toHaveCount(2);
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

describe('readerEndpoint', function (): void {
    it('returns the reader endpoint of a cluster with a reader member', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient(['DescribeDBClusters' => auroraCluster(readers: 2)], $rds);

        expect(Rds::readerEndpoint())->toBe('my-cluster.cluster-ro-abc123.ap-southeast-2.rds.amazonaws.com');
    });

    it('throws for a writer-only cluster, whose reader endpoint routes to the writer', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient(['DescribeDBClusters' => auroraCluster(readers: 0)], $rds);

        expect(fn (): ?string => Rds::readerEndpoint())
            ->toThrow(IntegrityCheckException::class, 'cluster "my-cluster", but it has no reader instance')
            ->toThrow(IntegrityCheckException::class, '`backups.source: writer`');
    });

    it('is null for a plain instance, which has no reader to prefer', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('not found', new AwsCommand('DescribeDBClusters'), ['code' => 'DBClusterNotFoundFault']),
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'my-db']]]),
        ], $rds);

        expect(Rds::readerEndpoint())->toBeNull();
    });

    it('is null without an RDS call when no database is declared', function (): void {
        $rds = [];
        bindMockRdsClient([], $rds);

        expect(Rds::readerEndpoint())->toBeNull()
            ->and($rds)->toBeEmpty();
    });
});

describe('writerEndpoint', function (): void {
    it('returns the cluster endpoint, even for a writer-only cluster', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-cluster']);

        $rds = [];
        bindMockRdsClient(['DescribeDBClusters' => auroraCluster(readers: 0)], $rds);

        expect(Rds::writerEndpoint())->toBe('my-cluster.cluster-abc123.ap-southeast-2.rds.amazonaws.com');
    });

    it('is null for a plain instance', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'my-db']);

        $rds = [];
        bindMockRdsClient([
            'DescribeDBClusters' => new RdsException('not found', new AwsCommand('DescribeDBClusters'), ['code' => 'DBClusterNotFoundFault']),
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'my-db']]]),
        ], $rds);

        expect(Rds::writerEndpoint())->toBeNull();
    });
});
