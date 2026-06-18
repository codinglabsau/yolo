<?php

declare(strict_types=1);

use Codinglabs\Yolo\Commands\SyncEnvironmentCommand;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('warns when a declared env-backed service has no live consumer', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  typesense:\n    version: \"30.2\"\n",
        'claims' => ['my-app' => []], // declared, but nothing consumes it
        'clusters' => ['my-app' => true],
    ], $captured);

    $warnings = SyncEnvironmentCommand::idleServiceWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('typesense')->toContain('no running app uses it');
});

it('does not warn when a live app consumes the declared service', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  typesense:\n    version: \"30.2\"\n",
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(SyncEnvironmentCommand::idleServiceWarnings())->toBe([]);
});

it('does not warn — or even read the registry — when no env-backed service is declared', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(SyncEnvironmentCommand::idleServiceWarnings())->toBe([]);

    // The cheap declared-gate short-circuits before the registry/ECS probe.
    expect(array_column($captured, 'name'))->not->toContain('ListObjectsV2');
});

it('suppresses the idle warning while a live app has not published its services yet', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  typesense:\n    version: \"30.2\"\n",
        'claims' => [], // nobody has published
        'clusters' => ['my-app' => true], // but a live app exists — possibly a consumer we can't yet see
    ], $captured);

    expect(SyncEnvironmentCommand::idleServiceWarnings())->toBe([]);
});
