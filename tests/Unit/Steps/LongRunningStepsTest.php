<?php

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Deploy\ExecuteDeployStepsStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheClusterStep;

it('flags the slow provisioning steps as LongRunning with a non-empty patience message', function (LongRunning $step) {
    expect(trim($step->patienceMessage()))->not->toBe('');
})->with([
    'cache cluster' => fn () => new SyncCacheClusterStep(),
    'deploy tasks' => fn () => new ExecuteDeployStepsStep('testing'),
]);
