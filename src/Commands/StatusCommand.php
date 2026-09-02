<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Tui\Tui;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Screen;
use Codinglabs\Yolo\Tui\Keyboard;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Tui\Panels\CachePanel;
use Codinglabs\Yolo\Tui\Panels\GroupPanel;
use Codinglabs\Yolo\Tui\Panels\StatusPanel;
use Codinglabs\Yolo\Tui\Panels\DatabasePanel;
use Codinglabs\Yolo\Tui\Panels\ServicesPanel;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Tui\Panels\DeploymentsPanel;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

class StatusCommand extends Command implements ReadOnlyCommand
{
    use RendersServiceStatus;

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the status as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->addOption('snapshot', null, InputOption::VALUE_NONE, 'Render a single frame instead of the live dashboard (for piping, screenshots, CI)')
            ->setDescription("Show the environment's live status dashboard (or a one-shot snapshot)");
    }

    public function handle(): int
    {
        if ($this->wantsDashboard()) {
            return $this->dashboard();
        }

        $statuses = static::gatherServiceStatuses();
        $queues = static::gatherQueueBacklogs();

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

        // Non-zero on a failed deployment so --snapshot works as a CI/health probe.
        return static::anyDeploymentFailed($statuses) ? 1 : 0;
    }

    protected function wantsDashboard(): bool
    {
        return static::shouldRenderDashboard(
            (bool) $this->option('json'),
            (bool) $this->option('snapshot'),
            $this->input->isInteractive(),
            $this->output->isDecorated(),
        );
    }

    /**
     * Pure so it can be pinned without a live terminal.
     */
    public static function shouldRenderDashboard(bool $json, bool $snapshot, bool $interactive, bool $decorated): bool
    {
        return ! $json && ! $snapshot && $interactive && $decorated;
    }

    protected function dashboard(): int
    {
        $environment = (string) $this->argument('environment');

        return (new Tui(
            screen: new Screen($this->output),
            keyboard: new Keyboard(),
            environment: $environment,
            panels: [
                new StatusPanel($this->output),
                ...array_map(
                    fn (ServerGroup $group): GroupPanel => new GroupPanel($group, $this->output),
                    Manifest::serverGroups(),
                ),
                new DeploymentsPanel($this->output),
                new DatabasePanel(),
                new CachePanel(),
                new ServicesPanel(),
            ],
            output: $this->output,
        ))->run();
    }
}
