<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command as AwsCommand;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Audit\RdsInspection;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app-db']);
});

it('returns null when no database is declared — nothing to inspect', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(RdsInspection::inspect())->toBeNull();
});

it('reads a plain instance with deletion protection on, plus engine, class and storage basics', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-db',
            'DeletionProtection' => true,
            'Engine' => 'mysql',
            'EngineVersion' => '8.0.39',
            'DBInstanceStatus' => 'available',
            'DBInstanceClass' => 'db.t3.medium',
            'AllocatedStorage' => 50,
            'MultiAZ' => true,
        ]]]),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds)->not->toBeNull()
        ->and($rds->cluster)->toBeFalse()
        ->and($rds->kind())->toBe('instance')
        ->and($rds->deletionProtectionEnabled())->toBeTrue()
        ->and($rds->instanceClass)->toBe('db.t3.medium')
        ->and($rds->allocatedStorage)->toBe(50)
        ->and($rds->multiAz)->toBeTrue()
        ->and($rds->basics())->toMatchArray([
            'Engine' => 'mysql 8.0.39',
            'Status' => 'available',
            'Class' => 'db.t3.medium',
            'Storage' => '50 GiB',
            'Multi-AZ' => 'yes',
        ]);
});

it('flags a plain instance with deletion protection off', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-db',
            'DeletionProtection' => false,
            'Engine' => 'mysql',
        ]]]),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->readable)->toBeTrue()
        ->and($rds->deletionProtectionEnabled())->toBeFalse();
});

it('defaults deletion protection to off when the attribute is absent — fail safe', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'app-db']]]),
    ], $captured);

    expect(RdsInspection::inspect()->deletionProtectionEnabled())->toBeFalse();
});

it('reads an Aurora cluster: writer first then readers, with member classes and deletion protection', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app.cluster-abc123.ap-southeast-2.rds.amazonaws.com']);

    $captured = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => [[
            'DBClusterIdentifier' => 'app',
            'DeletionProtection' => true,
            'Engine' => 'aurora-mysql',
            'EngineVersion' => '8.0.mysql_aurora.3.05.2',
            'Status' => 'available',
            'DBClusterMembers' => [
                ['DBInstanceIdentifier' => 'app-reader-1', 'IsClusterWriter' => false, 'PromotionTier' => 1],
                ['DBInstanceIdentifier' => 'app-writer', 'IsClusterWriter' => true, 'PromotionTier' => 0],
            ],
        ]]]),
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'app-writer', 'DBInstanceClass' => 'db.r6g.large'],
            ['DBInstanceIdentifier' => 'app-reader-1', 'DBInstanceClass' => 'db.r6g.large'],
        ]]),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->cluster)->toBeTrue()
        ->and($rds->kind())->toBe('Aurora cluster')
        ->and($rds->deletionProtectionEnabled())->toBeTrue()
        ->and($rds->members)->toHaveCount(2)
        // writer is sorted first, regardless of the order AWS returns members in
        ->and($rds->members[0])->toMatchArray(['identifier' => 'app-writer', 'role' => 'writer', 'class' => 'db.r6g.large'])
        ->and($rds->members[1])->toMatchArray(['identifier' => 'app-reader-1', 'role' => 'reader'])
        ->and($rds->basics())->toMatchArray(['Members' => '2 (1 writer, 1 reader)']);
});

it('degrades to unreadable (a warning, never an error) when the database is not found', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new RdsException('not found', new AwsCommand('DescribeDBInstances'), ['code' => 'DBInstanceNotFound']),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->readable)->toBeFalse()
        // an unreadable target is never "protected" — but it's a warning, not the
        // error an explicit false is.
        ->and($rds->deletionProtectionEnabled())->toBeFalse()
        ->and($rds->reason)->toContain('no matching database');
});

it('degrades to unreadable when the read is denied', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new RdsException('denied', new AwsCommand('DescribeDBInstances'), ['code' => 'AccessDenied']),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->readable)->toBeFalse()
        ->and($rds->reason)->toContain('access denied');
});

it('tolerates a member-class describe failure on a cluster — sizes omitted, not fatal', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app.cluster-abc123.ap-southeast-2.rds.amazonaws.com']);

    $captured = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => [[
            'DBClusterIdentifier' => 'app',
            'DeletionProtection' => true,
            'Engine' => 'aurora-mysql',
            'DBClusterMembers' => [
                ['DBInstanceIdentifier' => 'app-writer', 'IsClusterWriter' => true],
            ],
        ]]]),
        'DescribeDBInstances' => new RdsException('denied', new AwsCommand('DescribeDBInstances'), ['code' => 'AccessDenied']),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->readable)->toBeTrue()
        ->and($rds->members)->toHaveCount(1)
        ->and($rds->members[0]['class'])->toBeNull();
});

it('reads the network posture facts off the instance describe', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-db',
            'DeletionProtection' => true,
            'DBSubnetGroup' => ['DBSubnetGroupName' => 'vapor-group', 'VpcId' => 'vpc-vapor'],
            'VpcSecurityGroups' => [
                ['VpcSecurityGroupId' => 'sg-1', 'Status' => 'active'],
                ['VpcSecurityGroupId' => 'sg-2', 'Status' => 'active'],
            ],
            'PubliclyAccessible' => true,
        ]]]),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->subnetGroupName)->toBe('vapor-group')
        ->and($rds->vpcId)->toBe('vpc-vapor')
        ->and($rds->securityGroupIds)->toBe(['sg-1', 'sg-2'])
        ->and($rds->publiclyAccessible)->toBeTrue();
});

it('derives a cluster\'s VPC and public accessibility from its member instances', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app.cluster-abc123.ap-southeast-2.rds.amazonaws.com']);

    $captured = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => [[
            'DBClusterIdentifier' => 'app',
            'DeletionProtection' => true,
            'DBSubnetGroup' => 'app-cluster-group',
            'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-cluster']],
            'DBClusterMembers' => [
                ['DBInstanceIdentifier' => 'app-writer', 'IsClusterWriter' => true],
            ],
        ]]]),
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-writer',
            'DBInstanceClass' => 'db.r6g.large',
            'DBSubnetGroup' => ['DBSubnetGroupName' => 'app-cluster-group', 'VpcId' => 'vpc-env'],
            'PubliclyAccessible' => false,
        ]]]),
    ], $captured);

    $rds = RdsInspection::inspect();

    expect($rds->subnetGroupName)->toBe('app-cluster-group')
        ->and($rds->vpcId)->toBe('vpc-env')
        ->and($rds->securityGroupIds)->toBe(['sg-cluster'])
        ->and($rds->publiclyAccessible)->toBeFalse();
});
