<?php

use Codinglabs\Yolo\Commands\SyncAppCommand;

it('gives no advisory for a dedicated scheduler service', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]], 'scheduler' => []],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('gives no advisory when the web host that bundles the scheduler does not autoscale', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('advises onOneServer when the scheduler is bundled into an autoscaling web task', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())
        ->toContain('onOneServer()')
        ->toContain('web');
});

it('advises onOneServer when the scheduler rides the standalone queue (always autoscaled)', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())
        ->toContain('onOneServer()')
        ->toContain('queue');
});
