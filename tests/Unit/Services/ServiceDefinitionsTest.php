<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\ServiceDefinition;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('resolves every service case to a definition that knows its own case', function (): void {
    foreach (Service::cases() as $service) {
        $definition = $service->definition();

        expect($definition)->toBeInstanceOf(ServiceDefinition::class)
            ->and($definition->service())->toBe($service);
    }
});

it('declares the env-backed split: ivs has an env half, mediaconvert and rekognition are app-side only', function (): void {
    expect(Service::IVS->definition()->envBacked())->toBeTrue()
        ->and(Service::MEDIA_CONVERT->definition()->envBacked())->toBeFalse()
        ->and(Service::REKOGNITION->definition()->envBacked())->toBeFalse();
});

it('only env-backed services compose environment steps', function (): void {
    foreach (Service::cases() as $service) {
        if (! $service->definition()->envBacked()) {
            expect($service->definition()->environmentSteps())->toBe([]);
        }
    }

    expect(Service::IVS->definition()->environmentSteps())->not->toBeEmpty();
});

it('rejects a scalar or list offer — an offer is a map', function (mixed $offer): void {
    expect(fn () => Service::IVS->definition()->validateOffer($offer, 'yolo-environment-testing.yml'))
        ->toThrow(IntegrityCheckException::class, 'services.ivs');
})->with([
    'scalar true' => [true],
    'scalar string' => ['yes'],
    'list' => [['a', 'b']],
]);

it('accepts an empty or absent offer map', function (): void {
    Service::IVS->definition()->validateOffer([], 'yolo-environment-testing.yml');
    Service::IVS->definition()->validateOffer(null, 'yolo-environment-testing.yml');

    expect(true)->toBeTrue();
});

it('mediaconvert bakes the per-app role ARN into the build env when claimed', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['mediaconvert'],
    ]);

    expect(Service::MEDIA_CONVERT->definition()->buildValues())
        ->toBe(['AWS_MEDIACONVERT_ROLE_ID' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-mediaconvert-role']);
});

it('every definition contributes its dashboard context keys even when unconsumed', function (): void {
    $context = [];

    foreach (Service::cases() as $service) {
        $context = [...$context, ...$service->definition()->dashboardContext()];
    }

    expect($context)->toHaveKeys(['ivsLogGroup', 'mediaConvertQueueArn', 'rekognition'])
        ->and($context['ivsLogGroup'])->toBeNull()
        ->and($context['mediaConvertQueueArn'])->toBeNull()
        ->and($context['rekognition'])->toBeFalse();
});
