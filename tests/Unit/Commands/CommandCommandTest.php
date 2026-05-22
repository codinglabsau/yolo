<?php

use Codinglabs\Yolo\Commands\CommandCommand;

it('builds the execute-command invocation with a profile', function () {
    $args = CommandCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'arn:aws:ecs:ap-southeast-2:111:task/abc',
        command: '/bin/sh',
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

it('omits --profile when none is configured (e.g. running on AWS)', function () {
    $args = CommandCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'task-arn',
        command: 'php artisan migrate --force',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->not->toContain('--profile');
    expect($args)->toContain('--command', 'php artisan migrate --force');
});
