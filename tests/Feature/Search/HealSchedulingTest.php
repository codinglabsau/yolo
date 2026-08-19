<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Codinglabs\Yolo\Facades\Yolo;
use Codinglabs\Yolo\Enums\Service;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses(SearchTestbenchCase::class);

function scheduledCommands(): array
{
    return array_map(
        fn (Event $event): string => (string) $event->command,
        app(Schedule::class)->events(),
    );
}

it('registers the search commands when the manifest claims typesense', function (): void {
    expect(Artisan::all())
        ->toHaveKey('scout:reimport')
        ->toHaveKey('scout:heal');
});

it('answers the typesense claim through the facade', function (): void {
    expect(Yolo::manifest()->hasService(Service::TYPESENSE))->toBeTrue();
});

it('reads the running environment block through the facade', function (): void {
    // Testbench runs as `testing`, the fixture manifest's declared environment.
    expect(Yolo::manifest()->has('services'))->toBeTrue()
        ->and(Yolo::manifest()->get('services'))->toBe(['typesense']);
});

it('schedules the heal itself when the app is wired for Typesense', function (): void {
    // Set-and-forget: composer update + release = self-healing on. No kernel
    // line to remember, so no app can forget it.
    expect(collect(scheduledCommands())->contains(fn (string $command): bool => str_contains($command, 'scout:heal')))->toBeTrue();
});

it('stays out of the schedule when opted out', function (): void {
    config()->set('yolo.search.heal', false);

    expect(collect(scheduledCommands())->contains(fn (string $command): bool => str_contains($command, 'scout:heal')))->toBeFalse();
});

it('stays out of the schedule on an app without Typesense wiring', function (): void {
    config()->set('scout.typesense.client-settings', []);

    expect(collect(scheduledCommands())->contains(fn (string $command): bool => str_contains($command, 'scout:heal')))->toBeFalse();
});
