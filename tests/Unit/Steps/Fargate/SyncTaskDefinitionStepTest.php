<?php

use Codinglabs\Yolo\Steps\Fargate\SyncTaskDefinitionStep;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
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

    expect($payload['family'])->toBe('yolo-testing-my-app');
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

it('prefers the supplied tag over the manifest image override', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['image' => 'public.ecr.aws/nginx:stable']],
    ]);

    $payload = SyncTaskDefinitionStep::payload('26.21.2.1500');

    expect($payload['containerDefinitions'][0]['image'])
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:26.21.2.1500');
});

it('honours explicit task image override', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['image' => 'public.ecr.aws/nginx:stable']],
    ]);

    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['containerDefinitions'][0]['image'])->toBe('public.ecr.aws/nginx:stable');
});

it('falls back to defaults when manifest omits task config', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['cpu'])->toBe('512');
    expect($payload['memory'])->toBe('1024');
    expect($payload['containerDefinitions'][0]['portMappings'][0]['containerPort'])->toBe(8000);
    expect($payload['executionRoleArn'])->toBe('ecsTaskExecutionRole');
    expect($payload)->not->toHaveKey('taskRoleArn');
});

it('tags the task definition with the environment', function () {
    $payload = SyncTaskDefinitionStep::payload();

    expect($payload['tags'])->toContain(['key' => 'yolo:environment', 'value' => 'testing']);
});
