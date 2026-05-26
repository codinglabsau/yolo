<?php

use Codinglabs\Yolo\Resources\Fargate\EcsCluster;
use Codinglabs\Yolo\Resources\Fargate\EcsService;

it('derives the ECS cluster name from app + environment by default', function () {
    writeManifest([]);

    expect((new EcsCluster())->name())->toBe('yolo-testing-my-app');
});

it('honours an explicit ecs.cluster override in the manifest', function () {
    writeManifest(['ecs' => ['cluster' => 'shared-cluster']]);

    expect((new EcsCluster())->name())->toBe('shared-cluster');
});

it('names the ECS service (and task definition family) with the web suffix', function () {
    writeManifest([]);

    expect((new EcsService())->name())->toBe('yolo-testing-my-app-web');
});
