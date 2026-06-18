<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Codinglabs\Yolo\YoloServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base case for the non-CLI (runtime) tests that need a real booted Laravel app —
 * the YoloServiceProvider wiring the pure-unit reporter tests can't reach. Testbench
 * boots a minimal app with YOLO's provider auto-loaded; the burst gate reads the
 * YOLO_BURST_SERVICE environment, so each test sets it and calls refreshApplication().
 */
abstract class TestbenchCase extends BaseTestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        // Testbench swaps the global container to its Laravel app and nulls it on
        // teardown. The YOLO unit suite resolves bindings off Container::getInstance()
        // — notably 'environment', bound once in tests/Pest.php — so capture it and put
        // it back; otherwise a sibling unit file batched into the same --parallel worker
        // is left with a fresh container and fails with "Target class [environment]
        // does not exist".
        $this->previousContainer = Container::getInstance();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Container::setInstance($this->previousContainer);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [YoloServiceProvider::class];
    }
}
