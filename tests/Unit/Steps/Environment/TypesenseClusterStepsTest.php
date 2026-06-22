<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseNodesStep;
use Codinglabs\Yolo\Steps\Sync\Environment\BuildTypesenseImageStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncServicesClusterStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncSearchCertificateStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncSearchTargetGroupStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseAdminKeyStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncSearchListenerRuleStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseSecurityGroupStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseDiscoveryServicesStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

const TYPESENSE_OFFER = "services:\n  typesense:\n    version: \"29.0\"\n    cpu: 256\n    memory: 1024\n";

function bindTypesenseWorld(array &$captured, ?string $sharedEnv = null, string $manifest = TYPESENSE_OFFER): void
{
    bindServiceLifecycleWorld([
        'manifest' => $manifest,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
        'sharedEnv' => $sharedEnv,
    ], $captured);
}

it('generates the admin key into the env-shared .env exactly once', function (): void {
    $captured = [];
    bindTypesenseWorld($captured);

    // Plan: pending, no write.
    $planned = new SyncTypesenseAdminKeyStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('PutObject');

    // Apply: the key lands in the bucket .env.
    expect((new SyncTypesenseAdminKeyStep())([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutObject');
    expect($put['args']['Key'])->toBe('.env.environment.testing')
        ->and((string) $put['args']['Body'])->toMatch('/^TYPESENSE_API_KEY=[0-9a-f]{48}\n$/');
});

it('leaves an existing admin key alone and preserves the rest of the env file', function (): void {
    $captured = [];
    bindTypesenseWorld($captured, sharedEnv: "OTHER_SECRET=keep\nTYPESENSE_API_KEY=abc123\n");

    expect((new SyncTypesenseAdminKeyStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('skips the image build when the content tag already exists in ECR', function (): void {
    $captured = [];
    bindTypesenseWorld($captured, sharedEnv: "TYPESENSE_API_KEY=abc123\n");

    $ecrCaptured = [];
    bindRoutedEcrClient([
        'DescribeImages' => new Result(['imageDetails' => [['imageTags' => ['x']]]]),
    ], $ecrCaptured);

    expect((new BuildTypesenseImageStep())(['dry-run' => true]))->toBe(StepResult::SYNCED);

    $describe = collect($ecrCaptured)->firstWhere('name', 'DescribeImages');
    expect($describe['args']['imageIds'][0]['imageTag'])->toBe('29.0-' . substr(hash('sha256', Typesense::serverConfig() . '|' . implode(',', Typesense::peers())), 0, 12));
});

it('plans WOULD_BUILD without touching Docker when the tag is missing', function (): void {
    $captured = [];
    bindTypesenseWorld($captured, sharedEnv: "TYPESENSE_API_KEY=abc123\n");

    $ecrCaptured = [];
    bindRoutedEcrClient([
        'DescribeImages' => new AwsException('nope', new Command('DescribeImages'), ['code' => 'ImageNotFoundException']),
    ], $ecrCaptured);

    $planned = new BuildTypesenseImageStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_BUILD);
    expect($planned->changes())->not->toBeEmpty();
});

it('plans WOULD_BUILD on a greenfield pass where the admin key does not exist yet', function (): void {
    $captured = [];
    bindTypesenseWorld($captured);

    $ecrCaptured = [];
    bindRoutedEcrClient([], $ecrCaptured);

    $planned = new BuildTypesenseImageStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_BUILD);
    // No tag to look up — the plan must not even hit ECR.
    expect(array_column($ecrCaptured, 'name'))->not->toContain('DescribeImages');
});

it('cascades the cluster teardown: drain services, delete them, then the cluster', function (): void {
    $captured = [];
    bindRoutedEcsClient([
        'ListServices' => new Result(['serviceArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-testing-services/yolo-testing-typesense-0']]),
    ], $captured);

    (new ServicesCluster())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('UpdateService')->toContain('DeleteService')->toContain('DeleteCluster');
    expect(array_search('UpdateService', $names))->toBeLessThan(array_search('DeleteService', $names));
    expect(array_search('DeleteService', $names))->toBeLessThan(array_search('DeleteCluster', $names));
});

it('tears the cluster down once the offer is removed from the env manifest', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n", // offer removed → teardown
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $ecsCaptured = [];
    bindRoutedEcsClient([
        // Re-providing the world's liveness fixtures — this bind replaces the
        // ECS client bindServiceLifecycleWorld registered.
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
        'DescribeClusters' => new Result(['clusters' => [['clusterName' => 'yolo-testing-services', 'clusterArn' => 'arn:x', 'status' => 'ACTIVE']]]),
    ], $ecsCaptured);

    $planned = new SyncServicesClusterStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect($planned->changes())->not->toBeEmpty();
    expect(array_column($ecsCaptured, 'name'))->not->toContain('DeleteCluster');
});

it('wires the search ingress (target group, cert, listener rule) before the load-balanced nodes', function (): void {
    // A node is a load-balanced ECS service: ECS CreateService rejects a target
    // group that isn't yet associated with the ALB, and the association only
    // exists once the listener rule forwards to it. So the target group, the cert
    // (which also bootstraps the shared :443 listener) and the rule must all
    // precede the nodes — this exact ordering shipped a CreateService crash once.
    $steps = (new Typesense())->environmentSteps();
    $index = fn (string $step): int => (int) array_search($step, $steps, true);

    expect($index(SyncSearchTargetGroupStep::class))->toBeLessThan($index(SyncSearchCertificateStep::class));
    expect($index(SyncSearchCertificateStep::class))->toBeLessThan($index(SyncSearchListenerRuleStep::class));
    expect($index(SyncSearchListenerRuleStep::class))->toBeLessThan($index(SyncTypesenseNodesStep::class));
});

it('defers the missing nodes until the search target group is attached to a load balancer', function (): void {
    $captured = [];
    bindTypesenseWorld($captured, sharedEnv: "TYPESENSE_API_KEY=abc123\n");

    $ecsCaptured = [];
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
        'DescribeServices' => new Result(['services' => []]),
        'DescribeTaskDefinition' => new AwsException('nope', new Command('DescribeTaskDefinition'), ['code' => 'ClientException']),
    ], $ecsCaptured);

    // The target group exists but no listener rule forwards to it yet, so it has
    // no associated load balancer — CreateService would 403, so the step defers.
    $elbCaptured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg', 'LoadBalancerArns' => []]]]),
    ], $elbCaptured);

    $step = new SyncTypesenseNodesStep();

    expect($step([]))->toBe(StepResult::SKIPPED);
    // The pending nodes are still recorded — the plan shows them, apply just waits.
    expect($step->changes())->toHaveCount(3);
    expect(array_column($ecsCaptured, 'name'))->not->toContain('CreateService');
});

it('the nodes step plans every missing node and skips teardown (the cluster cascade owns it)', function (): void {
    $captured = [];
    bindTypesenseWorld($captured, sharedEnv: "TYPESENSE_API_KEY=abc123\n");

    $ecsCaptured = [];
    bindRoutedEcsClient([
        // Re-providing the world's liveness fixtures — this bind replaces the
        // ECS client bindServiceLifecycleWorld registered.
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
        'DescribeServices' => new Result(['services' => []]),
        'DescribeTaskDefinition' => new AwsException('nope', new Command('DescribeTaskDefinition'), ['code' => 'ClientException']),
    ], $ecsCaptured);

    $planned = new SyncTypesenseNodesStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->toHaveCount(3);
    expect(array_column($ecsCaptured, 'name'))->not->toContain('CreateService');
});

it('the nodes and discovery-services steps skip on teardown', function (string $step): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n", // offer removed → teardown
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect((new $step())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
})->with([
    SyncTypesenseNodesStep::class,
    SyncTypesenseDiscoveryServicesStep::class,
]);

it('authorises the API port from the ALB SG and both the API and peering ports node-to-node', function (): void {
    $captured = [];
    bindTypesenseWorld($captured);

    $ec2Captured = [];
    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-typesense-security-group', 'GroupId' => 'sg-typesense'],
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-alb'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $ec2Captured);

    expect((new SyncTypesenseSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $authorisations = collect($ec2Captured)->where('name', 'AuthorizeSecurityGroupIngress')->values();

    // 8108 from the ALB, then 8108 node-to-node (peer status + write forwarding),
    // then 8107 node-to-node (Raft peering).
    expect($authorisations)->toHaveCount(3)
        ->and($authorisations[0]['args']['IpPermissions'][0]['FromPort'])->toBe(8108)
        ->and($authorisations[0]['args']['IpPermissions'][0]['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-alb')
        ->and($authorisations[1]['args']['IpPermissions'][0]['FromPort'])->toBe(8108)
        ->and($authorisations[1]['args']['IpPermissions'][0]['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-typesense')
        ->and($authorisations[2]['args']['IpPermissions'][0]['FromPort'])->toBe(8107)
        ->and($authorisations[2]['args']['IpPermissions'][0]['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-typesense');
});

it('records all three baseline rules as pending on a greenfield plan', function (): void {
    $captured = [];
    bindTypesenseWorld($captured);

    $ec2Captured = [];
    bindMockEc2Client([
        // The typesense SG itself is absent — its own create is still pending — so
        // all three of its baseline rules are pending too.
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-alb'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $ec2Captured);

    $step = new SyncTypesenseSecurityGroupStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and($step->changes())->toHaveCount(3)
        ->and(collect($ec2Captured)->where('name', 'AuthorizeSecurityGroupIngress'))->toHaveCount(0);
});
