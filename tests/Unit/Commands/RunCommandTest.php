<?php

use Codinglabs\Yolo\Commands\RunCommand;

it('builds the execute-command invocation with a profile', function () {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'arn:aws:ecs:ap-southeast-2:111:task/abc',
        command: '/bin/sh',
        container: 'web',
        region: 'ap-southeast-2',
        profile: 'codinglabs',
    );

    expect($args)->toBe([
        'aws', 'ecs', 'execute-command',
        '--cluster', 'yolo-production-codinglabs',
        '--task', 'arn:aws:ecs:ap-southeast-2:111:task/abc',
        '--container', 'web',
        '--interactive',
        '--command', '/bin/sh',
        '--region', 'ap-southeast-2',
        '--profile', 'codinglabs',
    ]);
});

it('targets the container named after the service group', function () {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'task-arn',
        command: '/bin/sh',
        container: 'queue',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->toContain('--container', 'queue');
});

it('omits --profile when none is configured (e.g. running on AWS)', function () {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'task-arn',
        command: 'php artisan migrate --force',
        container: 'web',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->not->toContain('--profile');
    expect($args)->toContain('--command', 'php artisan migrate --force');
});
