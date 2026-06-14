<?php

use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Panels\ServicesPanel;
use Symfony\Component\Console\Output\BufferedOutput;

it('maps lifecycle states to theme colours', function (): void {
    expect(ServicesPanel::stateTheme('provision'))->toBe(Theme::Healthy)
        ->and(ServicesPanel::stateTheme('teardown'))->toBe(Theme::Danger)
        ->and(ServicesPanel::stateTheme('conflict'))->toBe(Theme::Danger)
        ->and(ServicesPanel::stateTheme('retain'))->toBe(Theme::Warning)
        ->and(ServicesPanel::stateTheme('app-side'))->toBe(Theme::Accent)
        ->and(ServicesPanel::stateTheme('off'))->toBe(Theme::Muted);
});

it('renders the gate as a themed table', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "domain: example.com\nservices:\n  typesense:\n    version: '29.0'\n    nodes: 3\n",
        'claims' => ['convict' => ['typesense']],
        'clusters' => ['convict' => true],
    ], $captured);

    $panel = new ServicesPanel('testing', new BufferedOutput());
    $panel->gather();
    $body = implode("\n", $panel->render(120));

    expect($body)->toContain('typesense')
        ->toContain('convict')                   // used by
        ->toContain('version=29.0')              // offer summary
        ->toContain('<fg=#A3E635>provision');    // healthy state colour
});
