<?php

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Tui\DeployObserver;

function rolloutStatus(ServerGroup $group, int $running, int $desired, ?string $rollout = null): array
{
    return ['group' => $group, 'running' => $running, 'desired' => $desired, 'rolloutState' => $rollout];
}

it('detects an in-flight rollout from gathered ECS state', function (): void {
    $statuses = [
        rolloutStatus(ServerGroup::WEB, 2, 3, 'IN_PROGRESS'),
        rolloutStatus(ServerGroup::QUEUE, 1, 1),
    ];

    expect(DeployObserver::active($statuses))->toBeTrue()
        ->and(DeployObserver::inProgress($statuses))->toHaveCount(1);
});

it('is inactive when nothing is rolling', function (): void {
    expect(DeployObserver::active([rolloutStatus(ServerGroup::WEB, 3, 3, 'COMPLETED')]))->toBeFalse();
});

it('summarises the live rollout as a banner', function (): void {
    expect(DeployObserver::banner([rolloutStatus(ServerGroup::WEB, 2, 3, 'IN_PROGRESS')]))->toBe('deploying web 2/3')
        ->and(DeployObserver::banner([rolloutStatus(ServerGroup::WEB, 3, 3)]))->toBeNull();
});
