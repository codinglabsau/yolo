<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command as AwsCommand;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\TypesenseSecurityGroup;
use Codinglabs\Yolo\Steps\Destroy\App\RemoveAppEnvFileStep;
use Codinglabs\Yolo\Steps\Destroy\App\RevokeTypesenseIngressStep;

function destroyServiceManifest(array $extra = []): void
{
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => true],
        ...$extra,
    ]);
}

it('revokes only this app\'s 8108 rule from the env Typesense security group', function (): void {
    destroyServiceManifest(['services' => ['typesense']]);

    $typesenseName = (new TypesenseSecurityGroup())->name();
    $taskName = (new EcsTaskSecurityGroup())->name();

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => $typesenseName, 'GroupId' => 'sg-typesense'],
            ['GroupName' => $taskName, 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            ['SecurityGroupRuleId' => 'sgr-8108', 'IsEgress' => false, 'IpProtocol' => 'tcp', 'FromPort' => 8108, 'ReferencedGroupInfo' => ['GroupId' => 'sg-task']],
        ]]),
    ], $captured);

    expect((new RevokeTypesenseIngressStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('RevokeSecurityGroupIngress');
});

it('skips the Typesense ingress revoke for an app that does not use the service', function (): void {
    destroyServiceManifest();

    expect((new RevokeTypesenseIngressStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});

it('deletes the per-app env file from the env config bucket', function (): void {
    destroyServiceManifest();

    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new Result([]),
        'DeleteObject' => new Result([]),
    ], $captured);

    expect((new RemoveAppEnvFileStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteObject');
});

it('reports WOULD_DELETE for the per-app env file without writing on the plan pass', function (): void {
    destroyServiceManifest();

    $captured = [];
    bindRoutedS3Client(['HeadObject' => new Result([])], $captured);

    expect((new RemoveAppEnvFileStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteObject');
});

it('skips the per-app env file when it is already absent', function (): void {
    destroyServiceManifest();

    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new S3Exception('Not found', new AwsCommand('HeadObject'), ['code' => 'NoSuchKey']),
    ], $captured);

    expect((new RemoveAppEnvFileStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteObject');
});
