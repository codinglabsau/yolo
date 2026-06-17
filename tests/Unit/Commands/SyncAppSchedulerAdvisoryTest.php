<?php

declare(strict_types=1);

use Codinglabs\Yolo\Commands\SyncAppCommand;

it('gives no advisory for a dedicated scheduler service', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]], 'scheduler' => true],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('gives no advisory when the web host that bundles the scheduler does not autoscale', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('advises onOneServer when the scheduler is bundled into an autoscaling web task', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())
        ->toContain('onOneServer()')
        ->toContain('web');
});

it('advises onOneServer when the scheduler rides the standalone queue (always autoscaled)', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())
        ->toContain('onOneServer()')
        ->toContain('queue');
});

it('gives no onOneServer advisory when the scheduler is disabled', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]], 'scheduler' => false],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('warns that cron runs nowhere when the scheduler is disabled', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => false],
    ]);

    expect(SyncAppCommand::schedulerDisabledWarning())
        ->toContain('scheduler is disabled')
        ->toContain('runs nowhere');
});

it('gives no disabled warning when the scheduler runs', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    expect(SyncAppCommand::schedulerDisabledWarning())->toBeNull();
});
