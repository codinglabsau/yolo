<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Codinglabs\Yolo\Facades\Yolo;
use Codinglabs\Yolo\Enums\Service;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses(TestbenchCase::class);

// Testbench's skeleton base path has no yolo.yml, so this boots the provider
// the way it lands in an app that never claimed the typesense service.

it('does not register the search commands when the manifest claims no typesense', function (): void {
    // Scout's stock config ships a populated typesense block whatever engine
    // the app runs, so config alone must not be enough to register — an app
    // on another engine may carry its own `scout:reimport` (scout-extended's
    // Algolia command) that YOLO's must never shadow.
    config()->set('scout.typesense.client-settings', ['api_key' => 'key', 'nodes' => []]);

    expect(Artisan::all())
        ->not->toHaveKey('scout:reimport')
        ->not->toHaveKey('scout:heal');
});

it('answers no typesense claim through the facade without a manifest', function (): void {
    expect(Yolo::manifest()->hasService(Service::TYPESENSE))->toBeFalse();
});

it('does not schedule the heal when the manifest claims no typesense', function (): void {
    config()->set('scout.typesense.client-settings', ['api_key' => 'key', 'nodes' => []]);

    $scheduled = array_map(
        fn (Event $event): string => (string) $event->command,
        app(Schedule::class)->events(),
    );

    expect(collect($scheduled)->contains(fn (string $command): bool => str_contains($command, 'scout:heal')))->toBeFalse();
});
