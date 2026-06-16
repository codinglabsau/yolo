<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\Service;

it('gives typesense its sizing defaults and an implications warning', function (): void {
    $typesense = Service::TYPESENSE->definition();

    expect($typesense->offerDefaults())->toBe(['nodes' => 3, 'cpu' => 256, 'memory' => 1024])
        ->and($typesense->implications())->toContain('cluster')->toContain('Fargate');
});

it('gives every service a one-line description', function (): void {
    foreach (Service::cases() as $service) {
        expect($service->definition()->description())->not->toBe('');
    }
});

it('leaves app-side services without env offer defaults or implications', function (): void {
    foreach ([Service::MEDIA_CONVERT, Service::REKOGNITION] as $service) {
        expect($service->definition()->offerDefaults())->toBe([])
            ->and($service->definition()->implications())->toBe('');
    }
});
