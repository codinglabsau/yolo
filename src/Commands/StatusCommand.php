<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Tui\Tui;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Screen;
use Codinglabs\Yolo\Tui\Keyboard;
use Codinglabs\Yolo\Tui\Panels\LogsPanel;
use Codinglabs\Yolo\Tui\Panels\CachePanel;
use Codinglabs\Yolo\Tui\Panels\AlarmsPanel;
use Codinglabs\Yolo\Tui\Panels\StatusPanel;
use Codinglabs\Yolo\Tui\Panels\MetricsPanel;
use Codinglabs\Yolo\Tui\Panels\DatabasePanel;
use Codinglabs\Yolo\Tui\Panels\ServicesPanel;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Tui\Panels\DeploymentsPanel;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

/**
 * The environment status surface. In a real terminal `yolo status <env>` opens the
 * live, tabbed read-only dashboard — Overview, Logs, Deployments and the service
 * gate, polled and redrawn until you quit. `--snapshot` (and any non-interactive
 * shell) renders a single one-shot frame instead; `--json` is the machine-readable
 * contract the `/yolo` skill and scripts consume.
 */
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
        // In a real terminal the live dashboard is the default; --snapshot, --json
        // and any non-interactive shell fall through to the one-shot frame below.
        if ($this->wantsDashboard()) {
            return $this->dashboard();
        }

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
        // `yolo status --snapshot` stays usable as a lightweight CI/health probe.
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
     * The dispatch decision, pure so it can be pinned without a live terminal: the
     * dashboard wins only in a real terminal — interactive stdin and a decorated
     * output — and never when the caller asked for a single frame (`--snapshot`) or
     * JSON. Everything else falls through to the one-shot snapshot frame.
     */
    public static function shouldRenderDashboard(bool $json, bool $snapshot, bool $interactive, bool $decorated): bool
    {
        return ! $json && ! $snapshot && $interactive && $decorated;
    }

    /**
     * Open the tabbed read-only dashboard over the environment and block until the
     * user quits. Navigation only — every tab is a live view, nothing mutates.
     */
    protected function dashboard(): int
    {
        $environment = (string) $this->argument('environment');

        return (new Tui(
            screen: new Screen($this->output),
            keyboard: new Keyboard(),
            environment: $environment,
            panels: [
                new StatusPanel($this->output),
                new MetricsPanel($this->output),
                new AlarmsPanel(),
                new LogsPanel(),
                new DeploymentsPanel($this->output),
                new DatabasePanel(),
                new CachePanel(),
                new ServicesPanel(),
            ],
            output: $this->output,
        ))->run();
    }
}
