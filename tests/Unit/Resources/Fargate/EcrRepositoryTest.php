<?php

use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('derives the ECR repository name from the manifest app name', function () {
    expect((new EcrRepository())->name())->toBe('my-app');
});

it('builds the ECR repository URI from account and region', function () {
    expect((new EcrRepository())->uri())
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app');
});
