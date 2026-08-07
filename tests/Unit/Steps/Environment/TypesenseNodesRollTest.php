<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseNodesStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

const ROLL_OFFER = "domain: example.com.au\nservices:\n  typesense:\n    version: \"30.2\"\n    cpu: 256\n    memory: 1024\n";

/**
 * A step with the real gate logic but no real waiting: the ECS stability
 * waiter is a no-op (ECS is mocked) and the between-attempts pause is
 * silenced so bounded polls spend their attempts instantly.
 */
function rollStepWithoutWaits(GuzzleMockHandler $guzzle): SyncTypesenseNodesStep
{
    return new class('testing', new Client(['handler' => HandlerStack::create($guzzle)])) extends SyncTypesenseNodesStep
    {
        protected function waitForStability(array $services): void {}

        protected function pause(int $seconds): void {}
    };
}

/**
 * The ECS world for a roll: a queue of DescribeServices answers (partition
 * reads each node twice — exists(), then the staleness check), the family's
 * latest revision, and a running task at 10.0.0.5 for the gate to find.
 *
 * @param  array<int, string>  $revisionByDescribe
 * @param  array<int, array<string, mixed>>  $listTasksQueue
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRollEcsWorld(array $revisionByDescribe, array $listTasksQueue, array &$captured): void
{
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => array_map(fn (array $taskArns): Result => new Result($taskArns), $listTasksQueue),
        'DescribeServices' => [
            ...array_map(fn (string $revision): Result => new Result(['services' => [[
                'status' => 'ACTIVE',
                'taskDefinition' => $revision,
            ]]]), $revisionByDescribe),
            // The surplus probe (nodes above the declared count) finds nothing.
            new AwsException('nope', new Command('DescribeServices'), ['code' => 'ServiceNotFoundException']),
        ],
        'DescribeTaskDefinition' => new Result(['taskDefinition' => ['taskDefinitionArn' => 'arn:td/new']]),
        'DescribeTasks' => new Result(['tasks' => [[
            'attachments' => [['details' => [['name' => 'privateIPv4Address', 'value' => '10.0.0.5']]]],
        ]]]),
    ], $captured);
}

it('rolls a stale node and only returns once the cluster proves converged', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => ROLL_OFFER,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
    ], $captured);

    $ecsCaptured = [];
    bindRollEcsWorld(
        // Node 0 reads stale (twice); nodes 1 and 2 already run the latest.
        revisionByDescribe: ['arn:td/old', 'arn:td/old', 'arn:td/new', 'arn:td/new', 'arn:td/new', 'arn:td/new'],
        listTasksQueue: [['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/replacement']]],
        captured: $ecsCaptured,
    );

    $elbCaptured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-search', 'TargetGroupArn' => 'arn:tg']]]),
        'DescribeTargetHealth' => new Result(['TargetHealthDescriptions' => [
            ['Target' => ['Id' => '10.0.0.5'], 'TargetHealth' => ['State' => 'healthy']],
        ]]),
    ], $elbCaptured);

    // One degraded sample first — the replacement still catching up resets the
    // consecutive run — then a clean run of samples and a leader per /debug.
    $guzzle = new GuzzleMockHandler([
        new Response(503, [], (string) json_encode(['ok' => false])),
        ...array_fill(0, 12, new Response(200, [], (string) json_encode(['ok' => true]))),
        new Response(200, [], (string) json_encode(['state' => 1, 'version' => '30.2'])),
    ]);

    expect((rollStepWithoutWaits($guzzle))([]))->toBe(StepResult::SYNCED);

    // The one stale node rolled — with the current grace period riding along —
    // and the whole probe budget was spent proving the cluster converged.
    $updates = collect($ecsCaptured)->where('name', 'UpdateService')->values();
    expect($updates)->toHaveCount(1)
        ->and($updates[0]['args']['service'])->toBe('yolo-testing-typesense-0')
        ->and($updates[0]['args']['healthCheckGracePeriodSeconds'])->toBe(600);
    expect($guzzle->count())->toBe(0);
});

it('aborts the roll loudly when the replacement never serves, leaving the remaining nodes untouched', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => ROLL_OFFER,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
    ], $captured);

    $ecsCaptured = [];
    bindRollEcsWorld(
        // Nodes 0 AND 1 read stale — the roll should die at node 0 and never
        // touch node 1.
        revisionByDescribe: ['arn:td/old', 'arn:td/old', 'arn:td/old', 'arn:td/old', 'arn:td/new', 'arn:td/new'],
        // The lifecycle liveness probe sees a task; every later runningTasks
        // read (the gate) finds none — the replacement is stuck in its boot
        // gate, exactly the state ECS "services stable" cannot distinguish.
        listTasksQueue: [
            ['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/live']],
            ['taskArns' => []],
        ],
        captured: $ecsCaptured,
    );

    $elbCaptured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-search', 'TargetGroupArn' => 'arn:tg']]]),
        'DescribeTargetHealth' => new Result(['TargetHealthDescriptions' => []]),
    ], $elbCaptured);

    $guzzle = new GuzzleMockHandler([]);

    expect(fn (): StepResult => (rollStepWithoutWaits($guzzle))([]))
        ->toThrow(RuntimeException::class, 'Aborting the Typesense node roll at yolo-testing-typesense-0 (1 of 2)');

    // Node 1 was never touched — resuming is a re-run of the sync, not a
    // half-rolled cluster.
    expect(collect($ecsCaptured)->where('name', 'UpdateService')->values())->toHaveCount(1);
});

it('names operator-side reachability in the abort when no sample ever gets an HTTP answer', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => ROLL_OFFER,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
    ], $captured);

    $ecsCaptured = [];
    bindRollEcsWorld(
        revisionByDescribe: ['arn:td/old', 'arn:td/old', 'arn:td/new', 'arn:td/new', 'arn:td/new', 'arn:td/new'],
        listTasksQueue: [['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/replacement']]],
        captured: $ecsCaptured,
    );

    $elbCaptured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-search', 'TargetGroupArn' => 'arn:tg']]]),
        'DescribeTargetHealth' => new Result(['TargetHealthDescriptions' => [
            ['Target' => ['Id' => '10.0.0.5'], 'TargetHealth' => ['State' => 'healthy']],
        ]]),
    ], $elbCaptured);

    // The replacement serves (stage 1 passes) but every /health sample is a
    // connection-level failure — the abort must point at reachability from
    // this machine, not send the operator chasing a healthy cluster.
    $guzzle = new GuzzleMockHandler(array_fill(0, 60, new ConnectException(
        'Connection refused',
        new Request('GET', 'https://search.example.com.au/health'),
    )));

    expect(fn (): StepResult => (rollStepWithoutWaits($guzzle))([]))
        ->toThrow(RuntimeException::class, 'no HTTP response was ever received');
});

it('judges a task serving only when its own IP answers healthy in the target group', function (): void {
    $task = fn (string $ip): array => [
        'attachments' => [['details' => [['name' => 'privateIPv4Address', 'value' => $ip]]]],
    ];
    $healthy = fn (string $ip): array => ['Target' => ['Id' => $ip], 'TargetHealth' => ['State' => 'healthy']];
    $unhealthy = fn (string $ip): array => ['Target' => ['Id' => $ip], 'TargetHealth' => ['State' => 'unhealthy']];

    // The replacement's own target must be healthy — a sibling's healthy
    // target proves nothing about the node just rolled.
    expect(SyncTypesenseNodesStep::tasksAreServing([$task('10.0.0.5')], [$healthy('10.0.0.5'), $unhealthy('10.0.0.9')]))->toBeTrue()
        ->and(SyncTypesenseNodesStep::tasksAreServing([$task('10.0.0.5')], [$unhealthy('10.0.0.5'), $healthy('10.0.0.9')]))->toBeFalse()
        ->and(SyncTypesenseNodesStep::tasksAreServing([$task('10.0.0.5')], []))->toBeFalse()
        ->and(SyncTypesenseNodesStep::tasksAreServing([], [$healthy('10.0.0.5')]))->toBeFalse();
});
