<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

/**
 * Recent CloudWatch logs per service group — the incident read surface for
 * "what is it saying right now". `--json` is the machine-readable form the
 * `/yolo` skill consumes.
 */
class StatusLogsCommand extends Command implements ReadOnlyCommand
{
    use RendersIncidentReads;

    protected function configure(): void
    {
        $this
            ->setName('status:logs')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit recent logs as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription('Show recent CloudWatch logs per service group');
    }

    public function handle(): int
    {
        $groups = static::gatherLogs();

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'app' => Manifest::current()['name'] ?? null,
                'environment' => $this->argument('environment'),
                'groups' => $groups,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status:logs · %s · %s', Manifest::current()['name'] ?? '', $this->argument('environment')));

        foreach ($this->logLines($groups) as $line) {
            $this->output->writeln($line);
        }

        return self::SUCCESS;
    }
}
