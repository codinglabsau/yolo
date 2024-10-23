<?php

namespace Codinglabs\Skeleton\Tests;

use Codinglabs\Skeleton\SkeletonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestClass;
use Illuminate\Database\Eloquent\Factories\Factory;

abstract class TestCase extends BaseTestClass
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Codinglabs\\Skeleton\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

//        $this->artisan('vendor:publish', ['--tag' => 'roles-migrations'])->run();
//        $this->artisan('migrate', ['--database' => 'testbench'])->run();
//        $this->loadLaravelMigrations(['--database' => 'testbench']);
    }

    protected function getPackageProviders($app)
    {
        return [
            SkeletonServiceProvider::class
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
    }
}
