<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Codinglabs\Yolo\YoloServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base case for the non-CLI (runtime) tests that need a real booted Laravel app —
 * the YoloServiceProvider wiring the pure-unit reporter tests can't reach. Testbench
 * boots a minimal app with YOLO's provider auto-loaded; the burst gate reads the
 * YOLO_BURST_* environment, so each test sets it and calls refreshApplication().
 */
abstract class TestbenchCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [YoloServiceProvider::class];
    }
}
