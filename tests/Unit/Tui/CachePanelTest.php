<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Panels\CachePanel;

it('summarises status, endpoint and engine', function (): void {
    $available = implode("\n", CachePanel::details('available', 'cache.abc.cache.amazonaws.com'));
    $missing = implode("\n", CachePanel::details('', ''));

    expect($available)->toContain('available')->toContain('cache.abc')->toContain('valkey')
        ->and($missing)->toContain('unknown')->toContain('—');
});

it('renders engine cpu, memory, connections and evictions charts', function (): void {
    $series = ['cpu' => [10.0, 50.0], 'memory' => [20.0, 30.0], 'connections' => [2.0, 8.0], 'evictions' => [0.0, 3.0]];

    $body = implode("\n", CachePanel::charts($series, 80));

    expect($body)->toContain('Engine CPU')
        ->toContain('Memory used')
        ->toContain('Connections')
        ->toContain('Evictions');
});
