<?php

use Codinglabs\Yolo\AwsResources;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('derives the ECR repository name from the manifest app name', function () {
    expect(AwsResources::ecrRepositoryName())->toBe('my-app');
});

it('builds the ECR repository URI from account and region', function () {
    expect(AwsResources::ecrRepositoryUri())
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app');
});
