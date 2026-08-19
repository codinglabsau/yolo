<?php

declare(strict_types=1);

namespace Tests;

use Tests\Fixtures\Search\Product;
use Illuminate\Foundation\Application;
use Laravel\Scout\ScoutServiceProvider;
use Tests\Fixtures\Search\ManifestedYoloServiceProvider;

/**
 * Base case for the runtime search tests, wired the way a Typesense app
 * arrives at runtime: a manifest claiming the service, client-settings +
 * model-settings present, but the Scout driver pinned to null so nothing
 * touches an engine — the commands talk Typesense over Laravel's Http
 * client, so Http::fake() covers everything they send.
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
        // Replaces the parent's list — registering both providers would boot
        // the base provider twice over the fixture subclass.
        return [ManifestedYoloServiceProvider::class, ScoutServiceProvider::class];
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
