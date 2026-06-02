<?php

use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;

it('derives the ECS cluster name from app + environment by default', function () {
    writeManifest([]);

    expect((new EcsCluster())->name())->toBe('yolo-testing-my-app');
});

it('names the ECS service (and task definition family) with the web suffix', function () {
    writeManifest([]);

    expect((new EcsService())->name())->toBe('yolo-testing-my-app-web');
});
