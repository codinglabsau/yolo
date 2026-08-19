<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Scout\EngineManager;
use Tests\Fixtures\Search\Product;
use Laravel\Scout\Engines\NullEngine;
use Illuminate\Foundation\Application;
use Laravel\Scout\ScoutServiceProvider;

/**
 * Base case for the runtime search tests: Testbench with Scout alongside the
 * YOLO provider, wired the way a Typesense app arrives at runtime — SCOUT_DRIVER
 * is `typesense` (what YOLO's provider reads to decide whether to register the
 * search commands), with client-settings + model-settings present. Scout's
 * `typesense` engine is rebound to NullEngine so model saves and the reimport's
 * replay pass exercise Scout's machinery without an engine ever touching the
 * network (Scout's real Typesense engine uses the typesense-php SDK, which
 * Http::fake() can't intercept). The commands' own cluster traffic goes through
 * Laravel's Http client, so Http::fake() covers everything they send.
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
        $app['config']->set('scout.driver', 'typesense');
        $app['config']->set('scout.prefix', 'test_');

        // Keep the replay pass off the network while the driver genuinely reads
        // as `typesense` (what YOLO's provider gate checks). The reimport pushes
        // changed rows through Scout's active engine (queueMakeSearchable), and
        // Scout's real Typesense engine talks over the typesense-php SDK, which
        // Http::fake() can't intercept — so rebind the engine to Scout's
        // NullEngine. The commands' own cluster traffic goes through Laravel's
        // Http client and stays covered by Http::fake(). afterResolving (not an
        // eager make) so it survives whatever order Testbench boots Scout in.
        $app->afterResolving(EngineManager::class, function (EngineManager $manager): void {
            $manager->extend('typesense', fn (): NullEngine => new NullEngine());
        });
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
