<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Commands\StatusCommand;

// The status dashboard's display logic lives in pure static helpers on the
// RendersServiceStatus trait, reached here through StatusCommand. They take plain
// arrays (the shapes AWS returns) so they can be pinned without mocking AWS.

it('clamps the progress bar between empty and full', function (): void {
    expect(StatusCommand::progressBar(0, 4, 8))->toBe(str_repeat('░', 8));
    expect(StatusCommand::progressBar(4, 4, 8))->toBe(str_repeat('█', 8));
    expect(StatusCommand::progressBar(2, 4, 8))->toBe(str_repeat('█', 4) . str_repeat('░', 4));
    // Never overflows when running exceeds desired (mid-rollout overlap).
    expect(StatusCommand::progressBar(6, 4, 8))->toBe(str_repeat('█', 8));
    // No desired count reads as complete, not divide-by-zero.
    expect(StatusCommand::progressBar(0, 0, 8))->toBe(str_repeat('█', 8));
});

it('parses the app version from a tagged image and skips digests / untagged refs', function (): void {
    expect(StatusCommand::versionFromImage('1234.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-prod-app:20260605-1'))
        ->toBe('20260605-1');
    // A digest reference has no human version.
    expect(StatusCommand::versionFromImage('1234.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-prod-app@sha256:abcdef'))
        ->toBeNull();
    // A registry host:port with no tag is not a version.
    expect(StatusCommand::versionFromImage('registry.example.com:5000/yolo-app'))->toBeNull();
    expect(StatusCommand::versionFromImage(''))->toBeNull();
});

it('reduces a task-definition ARN to group:revision', function (): void {
    expect(StatusCommand::revisionLabel('arn:aws:ecs:ap-southeast-2:1234:task-definition/yolo-prod-app-web:42'))
        ->toBe('web:42');
    expect(StatusCommand::revisionLabel('arn:aws:ecs:ap-southeast-2:1234:task-definition/yolo-prod-app-queue:7'))
        ->toBe('queue:7');
    expect(StatusCommand::revisionLabel(null))->toBeNull();
});

it('formats the task spec from CPU units / memory MiB', function (): void {
    expect(StatusCommand::formatSpec('512', '1024', 'FARGATE'))->toBe('0.5 vCPU · 1 GB · FARGATE');
    expect(StatusCommand::formatSpec('256', '512', 'SPOT'))->toBe('0.25 vCPU · 0.5 GB · SPOT');
    expect(StatusCommand::formatSpec('1024', '2048', 'FARGATE'))->toBe('1 vCPU · 2 GB · FARGATE');
    expect(StatusCommand::formatSpec(null, null, 'FARGATE'))->toBe('—');
});

it('colours the task count by convergence', function (): void {
    expect(StatusCommand::formatTasks(2, 2, 0))->toContain('2/2')->toContain('green');
    expect(StatusCommand::formatTasks(0, 1, 0))->toContain('0/1')->toContain('red');
    expect(StatusCommand::formatTasks(1, 2, 1))->toContain('1/2')->toContain('yellow');
    expect(StatusCommand::formatTasks(0, 0, 0))->toContain('0/0')->toContain('gray');
});

it('describes scaling bounds, policies, or a fixed/singleton service', function (): void {
    expect(StatusCommand::formatScaling(null, ServerGroup::SCHEDULER))->toBe('singleton');
    expect(StatusCommand::formatScaling(null, ServerGroup::WEB))->toBe('fixed');

    $scaling = [
        'min' => 1,
        'max' => 4,
        'policies' => [
            ['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0],
            ['metric' => 'ALBRequestCountPerTarget', 'target' => 1200.0],
        ],
    ];

    expect(StatusCommand::formatScaling($scaling, ServerGroup::WEB))
        ->toBe('1–4 auto (cpu 65%, req 1200)');

    $queue = ['min' => 0, 'max' => 10, 'policies' => [['metric' => 'backlog', 'target' => 0.0]]];

    expect(StatusCommand::formatScaling($queue, ServerGroup::QUEUE))->toBe('0–10 auto (backlog)');
});

it('formats live load against the CPU target, with web-only request/response', function (): void {
    $webLoad = ['cpu' => 43.2, 'memory' => 38.0, 'requests' => 410.0, 'response' => 0.126];

    expect(StatusCommand::formatLoad($webLoad, 65.0, ServerGroup::WEB))
        ->toBe('cpu 43.2%/65% · mem 38% · 410 rpm · 126 ms');

    // No CPU target → just the current reading; missing metric → em dash.
    $queueLoad = ['cpu' => null, 'memory' => 12.0, 'requests' => null, 'response' => null];

    expect(StatusCommand::formatLoad($queueLoad, null, ServerGroup::QUEUE))
        ->toBe('cpu — · mem 12%');
});

it('colours the rollout state', function (): void {
    expect(StatusCommand::formatRolloutState('IN_PROGRESS'))->toContain('IN PROGRESS')->toContain('blue');
    expect(StatusCommand::formatRolloutState('COMPLETED'))->toContain('COMPLETED')->toContain('green');
    expect(StatusCommand::formatRolloutState('FAILED'))->toContain('FAILED')->toContain('red');
    expect(StatusCommand::formatRolloutState(null))->toContain('—');
});

it('times an in-progress rollout from createdAt and a settled one across its span', function (): void {
    $now = 1_000;

    $inProgress = ['rolloutState' => 'IN_PROGRESS', 'createdAt' => new DateTimeImmutable('@940')];
    expect(StatusCommand::runningTime($inProgress, $now))->toBe(60);

    $completed = [
        'rolloutState' => 'COMPLETED',
        'createdAt' => new DateTimeImmutable('@800'),
        'updatedAt' => new DateTimeImmutable('@985'),
    ];
    expect(StatusCommand::runningTime($completed, $now))->toBe(185);
});

it('picks out in-progress and failed deployments', function (): void {
    $statuses = [
        ['group' => ServerGroup::WEB, 'rolloutState' => 'IN_PROGRESS'],
        ['group' => ServerGroup::QUEUE, 'rolloutState' => 'COMPLETED'],
        ['group' => ServerGroup::SCHEDULER, 'rolloutState' => 'FAILED'],
    ];

    expect(StatusCommand::inProgressDeployments($statuses))->toHaveCount(1);
    expect(StatusCommand::anyDeploymentFailed($statuses))->toBeTrue();

    $settled = [['group' => ServerGroup::WEB, 'rolloutState' => 'COMPLETED']];
    expect(StatusCommand::inProgressDeployments($settled))->toBe([]);
    expect(StatusCommand::anyDeploymentFailed($settled))->toBeFalse();
});

it('reads the launch type, defaulting to FARGATE and detecting Spot', function (): void {
    expect(StatusCommand::launchType(['launchType' => 'FARGATE']))->toBe('FARGATE');
    expect(StatusCommand::launchType([
        'capacityProviderStrategy' => [['capacityProvider' => 'FARGATE_SPOT', 'weight' => 1]],
    ]))->toBe('SPOT');
    expect(StatusCommand::launchType([]))->toBe('FARGATE');
});
