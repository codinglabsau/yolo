<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Panels\DatabasePanel;

it('summarises the endpoint, id and kind', function (): void {
    $instance = implode("\n", DatabasePanel::details('mydb.abc.ap-southeast-2.rds.amazonaws.com', ['identifier' => 'mydb', 'cluster' => false]));
    $cluster = implode("\n", DatabasePanel::details('c.cluster-x.ap-southeast-2.rds.amazonaws.com', ['identifier' => 'c', 'cluster' => true]));

    expect($instance)->toContain('mydb')->toContain('instance')
        ->and($cluster)->toContain('Aurora cluster');
});

it('renders cpu, connections, memory and latency charts, converting bytes and seconds', function (): void {
    $series = [
        'cpu' => [10.0, 40.0],
        'connections' => [5.0, 12.0],
        'memory' => [2.0 * 1048576, 3.0 * 1048576],   // bytes → 2 MB, 3 MB
        'readLatency' => [0.01, 0.02],                 // seconds → 10 ms, 20 ms
        'writeLatency' => [0.01, 0.02],
    ];

    $body = implode("\n", DatabasePanel::charts($series, 80));

    expect($body)->toContain('CPU')
        ->toContain('Connections')
        ->toContain('Freeable memory')
        ->toContain('Read latency')
        ->toContain('MB')
        ->toContain('ms');
});
