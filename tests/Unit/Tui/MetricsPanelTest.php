<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Tui\Panels\MetricsPanel;
use Symfony\Component\Console\Output\BufferedOutput;

/** Build the load shape the Metrics tab reads, with only the series that matter set. */
function loadWith(array $series): array
{
    return [
        'cpu' => null,
        'memory' => null,
        'requests' => null,
        'response' => null,
        'series' => array_merge(['cpu' => [], 'memory' => [], 'requests' => [], 'response' => []], $series),
    ];
}

it('is the Metrics tab on the m hotkey', function (): void {
    $panel = new MetricsPanel(new BufferedOutput());

    expect($panel->title())->toBe('Metrics')
        ->and($panel->hotkey())->toBe('m');
});

it('stacks cpu and memory charts per group, plus requests/response for web only', function (): void {
    $groups = [
        ['group' => ServerGroup::WEB, 'load' => loadWith(['cpu' => [10.0, 80.0], 'memory' => [40.0, 60.0], 'requests' => [5.0, 9.0], 'response' => [0.1, 0.25]])],
        ['group' => ServerGroup::QUEUE, 'load' => loadWith(['cpu' => [20.0], 'memory' => [30.0]])],
    ];

    $body = implode("\n", MetricsPanel::charts($groups, 80));

    expect($body)->toContain('web · CPU')
        ->toContain('web · Memory')
        ->toContain('web · Requests/min')
        ->toContain('web · Response')
        ->toContain('queue · CPU')
        ->toContain('queue · Memory')
        ->not->toContain('queue · Requests');   // only the web group gets request/response
});

it('shows an empty state when there are no groups', function (): void {
    expect(implode("\n", MetricsPanel::charts([], 80)))->toContain('No services to chart');
});
