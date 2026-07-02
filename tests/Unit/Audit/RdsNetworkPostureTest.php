<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command as AwsCommand;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Audit\RdsInspection;
use Codinglabs\Yolo\Audit\RdsNetworkPosture;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app-db']);
});

function bindInstanceWorld(array $instance): void
{
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-db',
            'DeletionProtection' => true,
            ...$instance,
        ]]]),
    ], $captured);
}

/** The env VPC plus the two YOLO security groups (RDS + the app's task SG). */
function bindManagedNetworkWorld(array $overrides = []): void
{
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-rds-security-group', 'GroupId' => 'sg-rds'],
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [[
            'IsEgress' => false,
            'IpProtocol' => 'tcp',
            'FromPort' => 3306,
            'ToPort' => 3306,
            'ReferencedGroupInfo' => ['GroupId' => 'sg-task'],
        ]]]),
        ...$overrides,
    ], $captured);
}

it('classifies the end-state as managed: env VPC, private subnet group, YOLO SG', function (): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'yolo-testing-private-subnet-group', 'VpcId' => 'vpc-env'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-rds', 'Status' => 'active']],
        'PubliclyAccessible' => false,
    ]);
    bindManagedNetworkWorld();

    $posture = RdsNetworkPosture::evaluate(RdsInspection::inspect());

    expect($posture->classification)->toBe(RdsNetworkPosture::MANAGED)
        ->and($posture->publiclyAccessible)->toBeFalse()
        ->and($posture->taskIngress)->toBeTrue();
});

it('classifies a database in a different VPC as external — valid, informational', function (): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'vapor-subnet-group', 'VpcId' => 'vpc-vapor'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-vapor']],
        'PubliclyAccessible' => false,
    ]);
    bindManagedNetworkWorld([
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ]);

    $posture = RdsNetworkPosture::evaluate(RdsInspection::inspect());

    expect($posture->classification)->toBe(RdsNetworkPosture::EXTERNAL)
        ->and($posture->taskIngress)->toBeFalse();
});

it('classifies a publicly accessible database as exposed regardless of VPC', function (): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'yolo-testing-private-subnet-group', 'VpcId' => 'vpc-env'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-rds']],
        'PubliclyAccessible' => true,
    ]);
    bindManagedNetworkWorld();

    expect(RdsNetworkPosture::evaluate(RdsInspection::inspect())->classification)
        ->toBe(RdsNetworkPosture::EXPOSED);
});

it('degrades to an unknown classification when the env VPC cannot be read', function (): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'some-group', 'VpcId' => 'vpc-somewhere'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-1']],
        'PubliclyAccessible' => false,
    ]);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Ec2Exception('denied', new AwsCommand('DescribeVpcs'), ['code' => 'UnauthorizedOperation']),
        'DescribeSecurityGroups' => new Ec2Exception('denied', new AwsCommand('DescribeSecurityGroups'), ['code' => 'UnauthorizedOperation']),
    ], $captured);

    $posture = RdsNetworkPosture::evaluate(RdsInspection::inspect());

    expect($posture->classification)->toBeNull()
        ->and($posture->taskIngress)->toBeNull();
});

it('reports no task ingress when no attached security group allows 3306 from the task SG', function (): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'yolo-testing-private-subnet-group', 'VpcId' => 'vpc-env'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-rds']],
        'PubliclyAccessible' => false,
    ]);
    bindManagedNetworkWorld([
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [[
            'IsEgress' => false,
            'IpProtocol' => 'tcp',
            'FromPort' => 3306,
            'ToPort' => 3306,
            'ReferencedGroupInfo' => ['GroupId' => 'sg-something-else'],
        ]]]),
    ]);

    expect(RdsNetworkPosture::evaluate(RdsInspection::inspect())->taskIngress)->toBeFalse();
});

it('returns null for an unreadable inspection — nothing to classify', function (): void {
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => []]),
    ], $captured);

    expect(RdsNetworkPosture::evaluate(RdsInspection::inspect()))->toBeNull();
});

it('accepts an all-traffic or port-range rule from the task SG as ingress', function (string $ipProtocol, ?int $fromPort, ?int $toPort): void {
    bindInstanceWorld([
        'DBSubnetGroup' => ['DBSubnetGroupName' => 'yolo-testing-private-subnet-group', 'VpcId' => 'vpc-env'],
        'VpcSecurityGroups' => [['VpcSecurityGroupId' => 'sg-rds']],
        'PubliclyAccessible' => false,
    ]);
    bindManagedNetworkWorld([
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [array_filter([
            'IsEgress' => false,
            'IpProtocol' => $ipProtocol,
            'FromPort' => $fromPort,
            'ToPort' => $toPort,
            'ReferencedGroupInfo' => ['GroupId' => 'sg-task'],
        ], fn (string|int|array|false|null $value): bool => $value !== null)]]),
    ]);

    expect(RdsNetworkPosture::evaluate(RdsInspection::inspect())->taskIngress)->toBeTrue();
})->with([
    'all traffic' => ['-1', null, null],
    'tcp range spanning 3306' => ['tcp', 3000, 3307],
]);
