<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Steps\Destroy\Environment\DisassociateWafStep;
use Codinglabs\Yolo\Steps\Destroy\Environment\TeardownEnvConfigBucketStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
    ]);
});

it('disassociates the web ACL from the load balancer', function (): void {
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => (new LoadBalancer())->name(), 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
    ], $elb);

    $waf = [];
    bindRoutedWafV2Client([
        'GetWebACLForResource' => new Result(['WebACL' => ['ARN' => 'arn:acl']]),
    ], $waf);

    expect((new DisassociateWafStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($waf, 'name'))->toContain('DisassociateWebACL');
});

it('skips disassociation when nothing is associated', function (): void {
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => (new LoadBalancer())->name(), 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
    ], $elb);

    $waf = [];
    bindRoutedWafV2Client(['GetWebACLForResource' => new Result([])], $waf);

    expect((new DisassociateWafStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($waf, 'name'))->not->toContain('DisassociateWebACL');
});

it('empties and deletes the env config bucket', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadBucket' => new Result([]),
        'ListObjectVersions' => new Result(['Versions' => [], 'DeleteMarkers' => []]),
        'DeleteBucket' => new Result([]),
    ], $captured);

    expect((new TeardownEnvConfigBucketStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteBucket');
});

it('reports the env config bucket as WOULD_DELETE on the plan pass', function (): void {
    $captured = [];
    bindRoutedS3Client(['HeadBucket' => new Result([])], $captured);

    expect((new TeardownEnvConfigBucketStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteBucket');
});
