<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

class BuildCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Build\PurgeBuildStep::class,
        Steps\Build\RetrieveEnvFileStep::class,
        Steps\Build\CopyApplicationStep::class,
        Steps\Build\ConfigureEnvAndVersionStep::class,
        Steps\Build\CreateTemporaryEnvStep::class,
        Steps\Build\ExecuteBuildStepsStep::class,
        Steps\Build\RestoreTemporaryEnvStep::class,
    ];

    protected array $fargateSteps = [
        Steps\Build\Fargate\GenerateEntrypointScriptStep::class,
        Steps\Build\Fargate\GenerateSupervisorConfigStep::class,
        Steps\Build\Fargate\LoginToEcrStep::class,
        Steps\Build\Fargate\BuildDockerImageStep::class,
        Steps\Build\Fargate\PushDockerImageStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('build')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Prepare a build of the application for deployment');
    }

    public function handle(): int
    {
        $appVersion = $this->option('app-version') ?? Carbon::now(Manifest::timezone())->format('y.W.N.Hi');
        $now = Carbon::now(Manifest::timezone());
        $expectedAppVersionPrefix = $now->format('y.W');
        $expectedAppVersionPrefixAlt = $now->format('y') . '.' . (int) $now->format('W');

        if (! str_starts_with($appVersion, $expectedAppVersionPrefix) && ! str_starts_with($appVersion, $expectedAppVersionPrefixAlt)) {
            error(sprintf('App version must start with %s or %s', $expectedAppVersionPrefix, $expectedAppVersionPrefixAlt));

            return self::FAILURE;
        }

        $this->input->setOption('app-version', $appVersion);

        if (Manifest::sessionDriver() === 'dynamodb' && ! $this->appHasAwsSdk()) {
            error('Sessions use the `dynamodb` driver (the web-app default), which needs `aws/aws-sdk-php` in your app. Add it (`composer require aws/aws-sdk-php`) or set `session.driver` to another value in yolo.yml.');

            return self::FAILURE;
        }

        if (Manifest::has('tasks')) {
            $this->steps = [...$this->steps, ...$this->fargateSteps];
        }

        intro("Building app version: {$appVersion}");

        return parent::handle();
    }

    /**
     * Whether the app ships aws/aws-sdk-php as a *production* dependency (directly
     * or transitively, e.g. via flysystem-s3) — required at runtime by the
     * dynamodb session driver. Reads composer.lock's production `packages` (not
     * `packages-dev`, which `composer install --no-dev` strips). If the lock is
     * absent we can't tell, so we don't block.
     */
    protected function appHasAwsSdk(): bool
    {
        $lock = Paths::base('composer.lock');

        if (! file_exists($lock)) {
            return true;
        }

        $packages = json_decode(file_get_contents($lock), true)['packages'] ?? [];

        return collect($packages)->contains(fn (array $package) => ($package['name'] ?? null) === 'aws/aws-sdk-php');
    }
}
