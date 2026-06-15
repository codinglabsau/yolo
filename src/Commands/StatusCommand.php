<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

/**
 * A one-shot snapshot of the app's services, load, scaling and any in-progress
 * deploy — rendered once and done, not a full-screen app. The live, polling
 * cockpit is `yolo tui` (its Status tab is this same picture, kept fresh); this
 * command is the quick "what's up right now" check, and the `--json` form is the
 * machine-readable contract the `/yolo` skill and scripts consume.
 */
class StatusCommand extends Command
{
    use RendersServiceStatus;

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the status as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Show a snapshot of the app's services, load, scaling and any in-progress deploy");
    }

    public function handle(): int
    {
        $statuses = static::gatherServiceStatuses();
        $queues = static::gatherQueueBacklogs();

        // `--json` is the machine-readable contract: emit the structured payload
        // and exit non-zero if a deploy is failed so it stays scriptable.
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'app' => Manifest::current()['name'] ?? null,
                'environment' => $this->argument('environment'),
                'groups' => static::jsonStatuses($statuses),
                'queues' => $queues,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return static::anyDeploymentFailed($statuses) ? 1 : 0;
        }

        intro(sprintf('yolo status · %s · %s', Manifest::current()['name'] ?? '', $this->argument('environment')));

        foreach ($this->statusLines($statuses, time(), queues: $queues) as $line) {
            $this->output->writeln($line);
        }

        // Exit non-zero when a deployment is currently failed, so a one-off
        // `yolo status` stays usable as a lightweight CI/health probe.
        return static::anyDeploymentFailed($statuses) ? 1 : 0;
    }
}
