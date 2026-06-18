<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncInternetGatewayAttachmentStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('plans WOULD_CREATE on a greenfield sync without throwing when neither the VPC nor the gateway exists yet', function (): void {
    // The plan pass of a first-ever env sync runs before SyncVpcStep /
    // SyncInternetGatewayStep have created anything — both describes come back
    // empty. The step must report pending drift, not throw
    // ResourceDoesNotExistException (the two-pass contract crash class).
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => []]),
        'DescribeInternetGateways' => new Result(['InternetGateways' => []]),
    ], $captured);

    expect((new SyncInternetGatewayAttachmentStep())(['dry-run' => true]))
        ->toBe(StepResult::WOULD_CREATE);

    expect(collect($captured)->pluck('name'))->not->toContain('AttachInternetGateway');
});

it('is SYNCED when the gateway is already attached to our VPC and available', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeInternetGateways' => new Result(['InternetGateways' => [[
            'InternetGatewayId' => 'igw-1',
            'Attachments' => [['VpcId' => 'vpc-1', 'State' => 'available']],
        ]]]),
    ], $captured);

    expect((new SyncInternetGatewayAttachmentStep())([]))->toBe(StepResult::SYNCED);
    expect(collect($captured)->pluck('name'))->not->toContain('AttachInternetGateway');
});

it('attaches the gateway to the VPC on apply when it exists but is unattached', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeInternetGateways' => new Result(['InternetGateways' => [[
            'InternetGatewayId' => 'igw-1',
            'Attachments' => [],
        ]]]),
    ], $captured);

    expect((new SyncInternetGatewayAttachmentStep())([]))->toBe(StepResult::CREATED);

    $attach = collect($captured)->firstWhere('name', 'AttachInternetGateway');
    expect($attach['args'])->toMatchArray([
        'InternetGatewayId' => 'igw-1',
        'VpcId' => 'vpc-1',
    ]);
});
