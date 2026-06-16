<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Panels\ServicesPanel;

it('maps lifecycle states to theme colours', function (): void {
    expect(ServicesPanel::stateTheme('provision'))->toBe(Theme::Healthy)
        ->and(ServicesPanel::stateTheme('teardown'))->toBe(Theme::Danger)
        ->and(ServicesPanel::stateTheme('conflict'))->toBe(Theme::Danger)
        ->and(ServicesPanel::stateTheme('retain'))->toBe(Theme::Warning)
        ->and(ServicesPanel::stateTheme('app-side'))->toBe(Theme::Accent)
        ->and(ServicesPanel::stateTheme('off'))->toBe(Theme::Muted);
});

it('renders the gate as a themed table, with the live typesense cluster detail', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "domain: example.com\nservices:\n  typesense:\n    version: '29.0'\n    nodes: 3\n",
        'claims' => ['convict' => ['typesense']],
        'clusters' => ['convict' => true],
    ], $captured);

    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);   // the typesense CPU/memory read returns no datapoints

    $panel = new ServicesPanel();
    $panel->gather();
    $body = implode("\n", $panel->render(120, 40));

    expect($body)->toContain('typesense')
        ->toContain('convict')                   // used by
        ->toContain('version=29.0')              // offer summary
        ->toContain('<fg=#A3E635>provision')     // healthy state colour
        ->toContain('typesense cluster')         // the live detail block
        ->toContain('3 nodes');
});

it('renders the typesense detail block with sizing and charts', function (): void {
    $block = implode("\n", ServicesPanel::typesenseBlock([
        'version' => '29.0',
        'nodes' => 3,
        'cpu' => 256,
        'memory' => 1024,
        'quorum' => 2,
        'cpuSeries' => [10.0, 40.0],
        'memorySeries' => [20.0, 30.0],
        'cluster' => 'yolo-production-services',
    ], 80));

    expect($block)->toContain('typesense cluster')
        ->toContain('v29.0')
        ->toContain('3 nodes')
        ->toContain('quorum 2')
        ->toContain('CPU')
        ->toContain('Memory');
});
