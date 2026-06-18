<?php

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Build\CopyApplicationStep;
use Codinglabs\Yolo\Steps\Deploy\ExecuteDeployStepsStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheClusterStep;
use Codinglabs\Yolo\Steps\Deploy\WaitForDeploymentHealthyStep;

it('flags the slow provisioning steps as LongRunning with a non-empty patience message', function (LongRunning $step): void {
    expect(trim($step->patienceMessage()))->not->toBe('');
})->with([
    'cache cluster' => fn (): SyncCacheClusterStep => new SyncCacheClusterStep(),
    'deploy tasks' => fn (): ExecuteDeployStepsStep => new ExecuteDeployStepsStep('testing'),
    'copy application' => fn (): CopyApplicationStep => new CopyApplicationStep('testing'),
    'wait for deployment healthy' => fn (): WaitForDeploymentHealthyStep => new WaitForDeploymentHealthyStep('testing'),
]);
