<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Foundation\Application;
use Laravel\Scout\ScoutServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;

/**
 * An app where a Typesense cluster is provisioned (client-settings present, as
 * YOLO's build injects) but Algolia is the active Scout driver. YOLO must not
 * claim the scout:reimport / scout:heal command names here — being in the config
 * is not the same as being the driver — so it never shadows whatever else (e.g.
 * scout-extended) binds `scout:reimport`. The positive path — commands present,
 * heal scheduled when Typesense IS the driver — is covered by the
 * SearchTestbenchCase suites.
 */
class UnconfiguredSearchCase extends TestbenchCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('scout.driver', 'algolia');
        $app['config']->set('scout.typesense.client-settings', [
            'api_key' => 'scoped-key',
            'nodes' => [
                ['host' => 'typesense-0.testing.internal', 'port' => 8108, 'protocol' => 'http', 'path' => ''],
            ],
        ]);
    }
}

uses(UnconfiguredSearchCase::class);

it('does not register the scout commands when Typesense is not configured', function (): void {
    $commands = $this->app[Kernel::class]->all();

    expect($commands)->not->toHaveKey('scout:reimport')
        ->and($commands)->not->toHaveKey('scout:heal');
});

it('does not schedule the heal when Typesense is not configured', function (): void {
    $scheduled = array_map(
        fn (Event $event): string => (string) $event->command,
        $this->app[Schedule::class]->events(),
    );

    expect(collect($scheduled)->contains(fn (string $command): bool => str_contains($command, 'scout:heal')))->toBeFalse();
});
