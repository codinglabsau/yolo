<?php

use Codinglabs\Yolo\AwsResources;

it('derives the ECS cluster name from app + environment by default', function () {
    writeManifest([]);

    expect(AwsResources::ecsClusterName())->toBe('yolo-testing-my-app');
});

it('honours an explicit ecs.cluster override in the manifest', function () {
    writeManifest(['ecs' => ['cluster' => 'shared-cluster']]);

    expect(AwsResources::ecsClusterName())->toBe('shared-cluster');
});

it('uses the app keyed name for the ECS service and task family', function () {
    writeManifest([]);

    expect(AwsResources::ecsServiceName())->toBe('yolo-testing-my-app');
    expect(AwsResources::ecsTaskFamily())->toBe('yolo-testing-my-app');
});
