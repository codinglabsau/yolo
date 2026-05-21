<?php

use Codinglabs\Yolo\AwsLookups;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('derives the ECR repository name from the manifest app name', function () {
    expect(AwsLookups::ecrRepositoryName())->toBe('my-app');
});

it('builds the ECR repository URI from account and region', function () {
    expect(AwsLookups::ecrRepositoryUri())
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app');
});
