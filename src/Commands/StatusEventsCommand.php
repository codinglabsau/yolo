<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

/**
 * Recent ECS service events per group — the deploy/placement narrative ECS
 * keeps (capacity, health-check, steady-state messages). `--json` is the
 * machine-readable form the `/yolo` skill consumes.
 */
class StatusEventsCommand extends Command implements ReadOnlyCommand
{
    use RendersIncidentReads;

    protected function configure(): void
    {
        $this
            ->setName('status:events')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit recent service events as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription('Show recent ECS service events per group');
    }

    public function handle(): int
    {
        $groups = static::gatherServiceEvents();

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'app' => Manifest::current()['name'] ?? null,
                'environment' => $this->argument('environment'),
                'groups' => $groups,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status:events · %s · %s', Manifest::current()['name'] ?? '', $this->argument('environment')));

        foreach ($this->serviceEventLines($groups) as $line) {
            $this->output->writeln($line);
        }

        return self::SUCCESS;
    }
}
