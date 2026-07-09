<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
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
