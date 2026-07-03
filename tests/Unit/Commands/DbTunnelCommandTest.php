<?php

declare(strict_types=1);

use Codinglabs\Yolo\Commands\DbTunnelCommand;

it('builds the port-forwarding session invocation with a profile', function (): void {
    $args = DbTunnelCommand::startSessionArgs(
        target: 'ecs:yolo-production-codinglabs_abc123_runtime-1',
        host: 'app-db.abc123.ap-southeast-2.rds.amazonaws.com',
        localPort: '13306',
        region: 'ap-southeast-2',
        profile: 'codinglabs',
    );

    expect($args)->toBe([
        'aws', 'ssm', 'start-session',
        '--target', 'ecs:yolo-production-codinglabs_abc123_runtime-1',
        '--document-name', 'AWS-StartPortForwardingSessionToRemoteHost',
        '--parameters', '{"host":["app-db.abc123.ap-southeast-2.rds.amazonaws.com"],"portNumber":["3306"],"localPortNumber":["13306"]}',
        '--region', 'ap-southeast-2',
        '--profile', 'codinglabs',
    ]);
});

it('omits the profile flag when no profile is configured', function (): void {
    $args = DbTunnelCommand::startSessionArgs(
        target: 'ecs:cluster_task_runtime',
        host: 'app-db.abc123.ap-southeast-2.rds.amazonaws.com',
        localPort: '13306',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->not->toContain('--profile');
});
