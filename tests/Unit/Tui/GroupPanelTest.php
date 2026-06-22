<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Tui\Panels\GroupPanel;
use Symfony\Component\Console\Output\BufferedOutput;

/** A deployed-group status row, with only the vitals fields the tab reads. */
function groupStatus(array $overrides = []): array
{
    return array_merge([
        'group' => ServerGroup::WEB,
        'exists' => true,
        'running' => 2,
        'desired' => 2,
        'pending' => 0,
        'launch' => 'FARGATE',
        'cpu' => '512',
        'memory' => '1024',
        'scaling' => ['min' => 1, 'max' => 4, 'policies' => [['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0]]],
        'cpuTarget' => 65.0,
        'load' => ['cpu' => 31.0, 'memory' => 44.0, 'requests' => 120.0, 'response' => 0.042, 'series' => ['cpu' => [], 'memory' => [], 'requests' => [], 'response' => []]],
    ], $overrides);
}

it('titles and hotkeys one tab per group', function (): void {
    expect((new GroupPanel(ServerGroup::WEB, new BufferedOutput()))->title())->toBe('Web')
        ->and((new GroupPanel(ServerGroup::WEB, new BufferedOutput()))->hotkey())->toBe('w')
        ->and((new GroupPanel(ServerGroup::QUEUE, new BufferedOutput()))->title())->toBe('Queue')
        ->and((new GroupPanel(ServerGroup::QUEUE, new BufferedOutput()))->hotkey())->toBe('u')
        ->and((new GroupPanel(ServerGroup::SCHEDULER, new BufferedOutput()))->title())->toBe('Scheduler')
        ->and((new GroupPanel(ServerGroup::SCHEDULER, new BufferedOutput()))->hotkey())->toBe('h');
});

it('renders group vitals — tasks, spec, scaling and live load', function (): void {
    $body = implode("\n", GroupPanel::vitals(groupStatus()));

    expect($body)->toContain('web')
        ->toContain('2/2')
        ->toContain('0.5 vCPU')
        ->toContain('1–4 auto')
        ->toContain('cpu 31%/65%')
        ->toContain('mem 44%');
});

it('collapses an undeployed group to a single not-deployed line', function (): void {
    $lines = GroupPanel::vitals(['group' => ServerGroup::QUEUE, 'exists' => false]);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('queue')->toContain('not deployed');
});

it('stacks cpu and memory charts, plus requests/response for web only', function (): void {
    $web = implode("\n", GroupPanel::charts(ServerGroup::WEB, [
        'cpu' => [10.0, 80.0], 'memory' => [40.0, 60.0], 'requests' => [5.0, 9.0], 'response' => [0.1, 0.25],
    ], 80));

    expect($web)->toContain('web · CPU')
        ->toContain('web · Memory')
        ->toContain('web · Requests/min')
        ->toContain('web · Response');

    $queue = implode("\n", GroupPanel::charts(ServerGroup::QUEUE, [
        'cpu' => [20.0], 'memory' => [30.0], 'requests' => [], 'response' => [],
    ], 80));

    expect($queue)->toContain('queue · CPU')
        ->toContain('queue · Memory')
        ->not->toContain('Requests')   // only the web group gets request/response
        ->not->toContain('Response');
});

it('formats log events as timestamped lines, oldest first', function (): void {
    $lines = GroupPanel::eventLines([
        ['timestamp' => 1718000000000, 'message' => 'booting'],
        ['timestamp' => 1718000001000, 'message' => 'ready'],
    ], 80);

    expect($lines)->toHaveCount(2)
        ->and(implode("\n", $lines))->toContain('booting')->toContain('ready');
});

it('truncates a long log message to the row width', function (): void {
    $lines = GroupPanel::eventLines([
        ['timestamp' => 1718000000000, 'message' => str_repeat('x', 200)],
    ], 40);

    // 40 - 11 (prefix) = 29 visible message chars, the last one the ellipsis.
    expect($lines[0])->toContain('…')
        ->and(mb_substr_count($lines[0], 'x'))->toBe(28);
});

it('shows an empty state when there are no log events', function (): void {
    expect(implode("\n", GroupPanel::eventLines([], 80)))->toContain('No recent log events');
});
