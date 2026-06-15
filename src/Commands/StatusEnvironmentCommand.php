<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

/**
 * The env-tier status roll-up — a compact health row per app in the environment
 * (each app's web service: task counts, rollout state and version), discovered
 * from the live ECS clusters in the env's namespace. The per-app detail (load,
 * scaling, queues) is `status` / `status:app`.
 */
class StatusEnvironmentCommand extends Command
{
    use RendersServiceStatus;

    protected function configure(): void
    {
        $this
            ->setName('status:environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the roll-up as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Roll up every app's status across an environment");
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $rows = static::gatherEnvStatuses($environment);

        // `--json` is the machine-readable contract: emit the roll-up and exit
        // non-zero if any app has a failed deploy so it stays scriptable.
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'environment' => $environment,
                'apps' => static::jsonEnvStatuses($rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return static::anyDeploymentFailed($rows) ? 1 : 0;
        }

        if ($rows === []) {
            info(sprintf("No live apps in '%s'.", $environment));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status · %s · all apps', $environment));

        foreach ($this->envRollupLines($rows) as $line) {
            $this->output->writeln($line);
        }

        return static::anyDeploymentFailed($rows) ? 1 : 0;
    }
}
