<?php

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Deploy\ExecuteDeployStepsStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheClusterStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncDynamoDbSessionsTableStep;

it('flags the slow provisioning steps as LongRunning with a non-empty patience message', function (LongRunning $step) {
    expect(trim($step->patienceMessage()))->not->toBe('');
})->with([
    'cache cluster' => fn () => new SyncCacheClusterStep(),
    'sessions table' => fn () => new SyncDynamoDbSessionsTableStep(),
    'deploy tasks' => fn () => new ExecuteDeployStepsStep('testing'),
]);
