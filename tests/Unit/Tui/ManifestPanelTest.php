<?php

use Codinglabs\Yolo\Tui\Panels\ManifestPanel;

it('shows the env manifest and the app config', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
        'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "domain: example.com\nservices:\n  typesense:\n    version: '29.0'\n    nodes: 3\n",
        'claims' => [],
        'clusters' => [],
    ], $captured);

    $panel = new ManifestPanel();
    $panel->gather();
    $body = implode("\n", $panel->render(120, 40));

    expect($body)->toContain('example.com')        // env domain
        ->toContain('services.typesense')          // env offer
        ->toContain('version=29.0')                // offer summary
        ->toContain('my-app')                      // app name
        ->toContain('ap-southeast-2');             // app region
});

it('offers a domain-edit modal on e / enter and ignores other keys', function (): void {
    $panel = new ManifestPanel();

    expect($panel->onKey('e'))->toBeInstanceOf(Closure::class)
        ->and($panel->onKey('enter'))->toBeInstanceOf(Closure::class)
        ->and($panel->onKey('x'))->toBeNull();
});
