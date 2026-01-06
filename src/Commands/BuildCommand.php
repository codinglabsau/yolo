<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
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

    protected function configure(): void
    {
        $this
            ->setName('build')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Prepare a build of the application for deployment');
    }

    public function handle(): void
    {
        $appVersion = $this->option('app-version') ?? Carbon::now(Manifest::timezone())->format('y.W.N.Hi');
        $now = Carbon::now(Manifest::timezone());
        $expectedAppVersionPrefix = $now->format('y.W');
        $expectedAppVersionPrefixAlt = $now->format('y') . '.' . (int) $now->format('W');

        if (! str_starts_with($appVersion, $expectedAppVersionPrefix) && ! str_starts_with($appVersion, $expectedAppVersionPrefixAlt)) {
            error(sprintf('App version must start with %s or %s', $expectedAppVersionPrefix, $expectedAppVersionPrefixAlt));

            return;
        }

        $this->input->setOption('app-version', $appVersion);

        intro("Building app version: {$appVersion}");

        parent::handle();
    }
}
