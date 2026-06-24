<?php

use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Build\CopyApplicationStep;
use Codinglabs\Yolo\Steps\Deploy\ExecuteDeployStepsStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncTypesenseKeyStep;
use Codinglabs\Yolo\Steps\Deploy\WaitForDeploymentHealthyStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncCacheClusterStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncServicesClusterStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseNamespaceStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseSecurityGroupStep;

it('flags the slow provisioning steps as LongRunning with a non-empty patience message', function (LongRunning $step): void {
    expect(trim($step->patienceMessage()))->not->toBe('');
})->with([
    'cache cluster' => fn (): SyncCacheClusterStep => new SyncCacheClusterStep(),
    'deploy tasks' => fn (): ExecuteDeployStepsStep => new ExecuteDeployStepsStep('testing'),
    'copy application' => fn (): CopyApplicationStep => new CopyApplicationStep('testing'),
    'wait for deployment healthy' => fn (): WaitForDeploymentHealthyStep => new WaitForDeploymentHealthyStep('testing'),
    'typesense key' => fn (): SyncTypesenseKeyStep => new SyncTypesenseKeyStep('testing'),
    'typesense namespace' => fn (): SyncTypesenseNamespaceStep => new SyncTypesenseNamespaceStep(),
    'typesense security group' => fn (): SyncTypesenseSecurityGroupStep => new SyncTypesenseSecurityGroupStep(),
    'services cluster' => fn (): SyncServicesClusterStep => new SyncServicesClusterStep(),
]);

it('reflects the teardown direction in a reused step patience message while destroying', function (): void {
    $securityGroup = new SyncTypesenseSecurityGroupStep();
    $namespace = new SyncTypesenseNamespaceStep();
    $cluster = new SyncServicesClusterStep();

    // Forward: the provisioning wording.
    expect($securityGroup->patienceMessage())->toContain('Configuring')
        ->and($namespace->patienceMessage())->toContain('Provisioning')
        ->and($cluster->patienceMessage())->toContain('Setting up');

    // Teardown: each speaks to removal/draining, not provisioning.
    Destroying::during(function () use ($securityGroup, $namespace, $cluster): void {
        expect($securityGroup->patienceMessage())->toContain('Removing')
            ->and($namespace->patienceMessage())->toContain('Removing')
            ->and($cluster->patienceMessage())->toContain('Draining');
    });
});
