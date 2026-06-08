<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

class StatusCommand extends Command
{
    use RendersServiceStatus;

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('snapshot', null, InputOption::VALUE_NONE, 'Render once and exit instead of running the live dashboard')
            ->setDescription("Show a live dashboard of the app's services, load, scaling and any in-progress deploy");
    }

    public function handle(): int
    {
        // A snapshot (or a non-interactive shell, where a redraw loop is pointless)
        // renders one frame and exits non-zero if a deployment is currently failed.
        if ($this->option('snapshot') || ! $this->input->isInteractive()) {
            $statuses = static::gatherServiceStatuses();

            foreach ($this->frame($statuses) as $line) {
                $this->output->writeln($line);
            }

            return static::anyDeploymentFailed($statuses) ? 1 : 0;
        }

        $this->runLiveDashboard();
    }

    /**
     * Poll forever, redrawing the dashboard every few seconds, until the operator
     * quits (Ctrl-C). It watches steady state *and* picks up any deploy that
     * starts while it's open — so it never settles itself.
     */
    protected function runLiveDashboard(): never
    {
        // Hide the cursor for a clean redraw, but only when pcntl can guarantee we
        // restore it on Ctrl-C — no hard pcntl dependency, so a build without it
        // simply keeps the cursor visible rather than risking leaving it hidden.
        if (extension_loaded('pcntl')) {
            $restore = fn () => $this->output->write("\e[?25h");

            register_shutdown_function($restore);
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($restore): void {
                $restore();
                exit(130);
            });
            pcntl_signal(SIGTERM, function () use ($restore): void {
                $restore();
                exit(143);
            });

            $this->output->write("\e[?25l");
        }

        $this->output->write("\e[2J");

        while (true) {
            $this->paint($this->frame(static::gatherServiceStatuses()));

            sleep(5);
        }
    }

    /**
     * The full frame: a header line plus the shared status panels.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function frame(array $statuses): array
    {
        return [
            '',
            sprintf(
                '  <options=bold>yolo status</> <fg=gray>· %s · %s · %s</>',
                Manifest::current()['name'] ?? '',
                $this->argument('environment'),
                date('H:i:s'),
            ),
            '',
            ...$this->statusLines($statuses, time()),
        ];
    }

    /**
     * Repaint in place: home the cursor, overwrite each line (clearing it first),
     * then wipe anything below so a shrinking frame leaves no stale rows. Low
     * flicker — no full screen clear each tick.
     *
     * @param  array<int, string>  $lines
     */
    protected function paint(array $lines): void
    {
        $this->output->write("\e[H");

        foreach ($lines as $line) {
            $this->output->write("\e[2K");
            $this->output->writeln($line);
        }

        $this->output->write("\e[J");
    }
}
