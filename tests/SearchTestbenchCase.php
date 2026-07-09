<?php

declare(strict_types=1);

namespace Tests;

use Tests\Fixtures\Search\Product;
use Illuminate\Foundation\Application;
use Laravel\Scout\ScoutServiceProvider;

/**
 * Base case for the runtime search tests: Testbench with Scout alongside the
 * YOLO provider, wired the way a Typesense app arrives at runtime —
 * client-settings + model-settings present (what the search commands key
 * off), but the Scout DRIVER pinned to null so model saves and the replay
 * pass exercise Scout's machinery without an engine ever touching the
 * network. The commands talk to Typesense through Laravel's Http client,
 * so Http::fake() covers everything they send.
 */
abstract class SearchTestbenchCase extends TestbenchCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('scout.driver', 'null');
        $app['config']->set('scout.prefix', 'test_');
        $app['config']->set('scout.typesense.client-settings', [
            'api_key' => 'scoped-key',
            'nodes' => [
                ['host' => 'typesense-0.testing.internal', 'port' => 8108, 'protocol' => 'http', 'path' => ''],
            ],
        ]);
        $app['config']->set('scout.typesense.model-settings', [
            Product::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'name', 'type' => 'string'],
                    ],
                ],
            ],
        ]);
    }
}
