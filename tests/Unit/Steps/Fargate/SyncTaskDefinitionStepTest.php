<?php

use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskDefinitionStep;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => [
            'web' => [
                'port' => 9000,
                'cpu' => '1024',
                'memory' => '2048',
                'execution-role' => 'custom-execution-role',
                'task-role' => 'custom-task-role',
            ],
        ],
    ]);
});

it('renders a Fargate-compatible task definition payload', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['family'])->toBe('yolo-testing-my-app-web');
    expect($payload['networkMode'])->toBe('awsvpc');
    expect($payload['requiresCompatibilities'])->toBe(['FARGATE']);
    expect($payload['cpu'])->toBe('1024');
    expect($payload['memory'])->toBe('2048');
    expect($payload['executionRoleArn'])->toBe('custom-execution-role');
    expect($payload['taskRoleArn'])->toBe('custom-task-role');
});

it('renders web container with manifest port', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'])->toHaveCount(1);
    expect($payload['containerDefinitions'][0]['name'])->toBe('web');
    expect($payload['containerDefinitions'][0]['portMappings'][0])->toBe([
        'containerPort' => 9000,
        'hostPort' => 9000,
        'protocol' => 'tcp',
    ]);
});

it('defaults image to the app ECR repository when not overridden', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'][0]['image'])
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:latest');
});

it('pins image to the supplied tag when one is passed', function () {
    $payload = SyncTaskDefinitionStep::payload('26.21.2.1500');

    expect($payload['containerDefinitions'][0]['image'])
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:26.21.2.1500');
});

it('falls back to defaults when manifest omits task config', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindMockIamClient([
        'yolo-testing-ecs-task-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-task-role',
        'yolo-testing-ecs-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);

    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['cpu'])->toBe('512');
    expect($payload['memory'])->toBe('1024');
    expect($payload['containerDefinitions'][0]['portMappings'][0]['containerPort'])->toBe(8000);
    expect($payload['taskRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-ecs-task-role');
    expect($payload['executionRoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role');
});

it('wires the container stop timeout to the shutdown-timings resolver', function () {
    expect(SyncTaskDefinitionStep::payload()['containerDefinitions'][0]['stopTimeout'])
        ->toBe(ShutdownTimings::stopTimeout());
});

it('enables init process in the web container for proper PID 1 signal handling', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'][0]['linuxParameters'])->toBe([
        'initProcessEnabled' => true,
    ]);
});

it('tags the task definition with the environment', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['tags'])->toContain(['key' => 'yolo:environment', 'value' => 'testing']);
});
