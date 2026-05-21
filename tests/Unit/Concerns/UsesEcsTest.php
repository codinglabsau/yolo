<?php

use Codinglabs\Yolo\AwsLookups;

it('derives the ECS cluster name from app + environment by default', function () {
    writeManifest([]);

    expect(AwsLookups::ecsClusterName())->toBe('yolo-testing-my-app');
});

it('honours an explicit ecs.cluster override in the manifest', function () {
    writeManifest(['ecs' => ['cluster' => 'shared-cluster']]);

    expect(AwsLookups::ecsClusterName())->toBe('shared-cluster');
});

it('uses the app keyed name for the ECS service and task family', function () {
    writeManifest([]);

    expect(AwsLookups::ecsServiceName())->toBe('yolo-testing-my-app');
    expect(AwsLookups::ecsTaskFamily())->toBe('yolo-testing-my-app');
});
