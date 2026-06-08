<?php

use Aws\Result;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskDefinitionStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => [
            'web' => [
                'port' => 9000,
                'cpu' => '1024',
                'memory' => '2048',
            ],
        ],
    ]);

    // The payload resolves the managed task (per-app) + execution (env-shared)
    // role ARNs by scanning the account role list.
    bindMockIamClient([
        'yolo-testing-my-app-ecs-task-role' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role',
        'yolo-testing-ecs-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);
});

it('renders a Fargate-compatible task definition payload', function (): void {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['family'])->toBe('yolo-testing-my-app-web');
    expect($payload['networkMode'])->toBe('awsvpc');
    expect($payload['requiresCompatibilities'])->toBe(['FARGATE']);
    expect($payload['cpu'])->toBe('1024');
    expect($payload['memory'])->toBe('2048');
    expect($payload['executionRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role');
    expect($payload['taskRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role');
});

it('renders web container with manifest port', function (): void {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'])->toHaveCount(1);
    expect($payload['containerDefinitions'][0]['name'])->toBe('web');
    expect($payload['containerDefinitions'][0]['portMappings'][0])->toBe([
        'containerPort' => 9000,
        'hostPort' => 9000,
        'protocol' => 'tcp',
    ]);
});

it('defaults image to the app ECR repository when not overridden', function (): void {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'][0]['image'])
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:latest');
});

it('pins image to the supplied tag when one is passed', function (): void {
    $payload = SyncTaskDefinitionStep::payload(imageTag: '26.21.2.1500');

    expect($payload['containerDefinitions'][0]['image'])
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:26.21.2.1500');
});

it('names the container after the role and passes it as the command', function (): void {
    $payload = SyncTaskDefinitionStep::payload(ServerGroup::QUEUE);

    expect($payload['family'])->toBe('yolo-testing-my-app-queue');
    expect($payload['containerDefinitions'][0]['name'])->toBe('queue');
    expect($payload['containerDefinitions'][0]['command'])->toBe(['queue']);
});

it('maps no port for a headless worker group (queue/scheduler)', function (): void {
    expect(SyncTaskDefinitionStep::payload(ServerGroup::SCHEDULER)['containerDefinitions'][0])
        ->not->toHaveKey('portMappings');
});

it('sizes queue and scheduler smaller by default than web', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    bindMockIamClient([
        'yolo-testing-my-app-ecs-task-role' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role',
        'yolo-testing-ecs-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);

    $queue = SyncTaskDefinitionStep::payload(ServerGroup::QUEUE);

    expect($queue['cpu'])->toBe('256');
    expect($queue['memory'])->toBe('512');
});

it('falls back to defaults when manifest omits task config', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindMockIamClient([
        'yolo-testing-my-app-ecs-task-role' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role',
        'yolo-testing-ecs-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);

    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['cpu'])->toBe('512');
    expect($payload['memory'])->toBe('1024');
    expect($payload['containerDefinitions'][0]['portMappings'][0]['containerPort'])->toBe(8000);
    expect($payload['taskRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role');
    expect($payload['executionRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role');
});

it('wires the container stop timeout to the shutdown-timings resolver', function (): void {
    expect(SyncTaskDefinitionStep::payload()['containerDefinitions'][0]['stopTimeout'])
        ->toBe(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB));
});

it('enables init process in the web container for proper PID 1 signal handling', function (): void {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'][0]['linuxParameters'])->toBe([
        'initProcessEnabled' => true,
    ]);
});

it('tags the task definition with the environment', function (): void {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['tags'])->toContain(['key' => 'yolo:environment', 'value' => 'testing']);
});

/** A live revision rendering the desired payload, plus AWS-derived enrichment. */
function liveTaskDefinition(array $overrides = []): array
{
    return array_merge(
        Arr::except(SyncTaskDefinitionStep::payload(), ['tags']),
        ['revision' => 7, 'status' => 'ACTIVE', 'taskDefinitionArn' => 'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-web:7'],
        $overrides,
    );
}

it('is in sync when the registered revision already renders the desired payload', function (): void {
    // The acceptance criterion: a no-op sync must not register a fresh revision —
    // it records no change, gets pruned before apply, and lets the sync report
    // "Already in sync".
    $captured = [];
    bindRoutedEcsClient([
        'DescribeTaskDefinition' => new Result(['taskDefinition' => liveTaskDefinition()]),
    ], $captured);

    $step = new SyncTaskDefinitionStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('RegisterTaskDefinition');
});

it('records drift on the plan pass and registers a new revision on apply', function (): void {
    // A drifted attribute (here the CPU sizing) the live revision no longer matches.
    $drifted = liveTaskDefinition(['cpu' => '9999']);

    $captured = [];
    bindRoutedEcsClient([
        'DescribeTaskDefinition' => new Result(['taskDefinition' => $drifted]),
    ], $captured);

    $plan = new SyncTaskDefinitionStep();
    expect($plan(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($plan->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('RegisterTaskDefinition');

    $captured = [];
    bindRoutedEcsClient([
        'DescribeTaskDefinition' => new Result(['taskDefinition' => $drifted]),
    ], $captured);

    expect((new SyncTaskDefinitionStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->toContain('RegisterTaskDefinition');
});

it('ignores AWS-derived enrichment fields when diffing (no phantom drift)', function (): void {
    // The live revision carries fields YOLO never sets (revision, status, ARN, and
    // container-level defaults). These must not read as drift.
    $live = liveTaskDefinition();
    $live['containerDefinitions'][0]['mountPoints'] = [];
    $live['containerDefinitions'][0]['volumesFrom'] = [];
    $live['containerDefinitions'][0]['environment'] = [];
    $live['requiresAttributes'] = [['name' => 'ecs.capability.execution-role-awslogs']];

    $captured = [];
    bindRoutedEcsClient([
        'DescribeTaskDefinition' => new Result(['taskDefinition' => $live]),
    ], $captured);

    expect((new SyncTaskDefinitionStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('RegisterTaskDefinition');
});
