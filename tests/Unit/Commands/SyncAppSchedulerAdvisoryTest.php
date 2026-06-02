<?php

use Codinglabs\Yolo\Commands\SyncAppCommand;

it('gives no advisory when autoscaling is not configured', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['scheduler' => true]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('gives no advisory when the scheduler is not bundled', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())->toBeNull();
});

it('advises onOneServer and service separation when autoscaling a bundled scheduler', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['scheduler' => true, 'autoscaling' => ['max' => 4]]],
    ]);

    expect(SyncAppCommand::schedulerAdvisory())
        ->toContain('onOneServer()')
        ->toContain('LPX-649');
});
