<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Tui\Tui;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Screen;
use Codinglabs\Yolo\Tui\Keyboard;
use Codinglabs\Yolo\Tui\Panels\LogsPanel;
use Codinglabs\Yolo\Tui\Panels\StatusPanel;
use Codinglabs\Yolo\Tui\Panels\ManifestPanel;
use Codinglabs\Yolo\Tui\Panels\ServicesPanel;
use Codinglabs\Yolo\Tui\Panels\DeploymentsPanel;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

/**
 * The interactive dashboard — a tabbed terminal UI over the environment: live
 * status, the service gate, deployments + rollback, logs, and the manifest, in
 * the Outrun theme. It supersedes nothing — `yolo status` and friends stay as
 * one-shot commands; `tui` is the live cockpit on top of them.
 *
 * With no environment argument it prompts for one (auto-selecting the only
 * environment when there's just one).
 *
 *   yolo tui                # prompt for the environment
 *   yolo tui production     # straight in
 */
class TuiCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('tui')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment name (prompts when omitted)')
            ->setDescription('Open the interactive dashboard — status, services, deployments, logs and the manifest');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        // Resolve the environment before the base command validates it, so a
        // bare `yolo tui` can prompt instead of failing on a missing argument.
        if ($input->getArgument('environment') === null) {
            $environment = $this->promptEnvironment();

            if ($environment === null) {
                return self::FAILURE;
            }

            $input->setArgument('environment', $environment);
        }

        return parent::execute($input, $output);
    }

    public function handle(): int
    {
        if (! $this->input->isInteractive() || ! $this->output->isDecorated()) {
            error('yolo tui is an interactive dashboard — run it in a real terminal (use `yolo status` for a one-off frame).');

            return self::FAILURE;
        }

        $environment = (string) $this->argument('environment');

        return (new Tui(
            screen: new Screen($this->output),
            keyboard: new Keyboard(),
            environment: $environment,
            panels: [
                new StatusPanel($this->output),
                new ServicesPanel($environment, $this->output),
                new DeploymentsPanel($environment, $this->output),
                new LogsPanel(),
                new ManifestPanel(),
            ],
            output: $this->output,
        ))->run();
    }

    /**
     * The environment to open: the manifest's sole environment when there's only
     * one, otherwise a picker. Returns null (with the reason surfaced) when the
     * manifest is missing/empty or the shell can't prompt.
     */
    protected function promptEnvironment(): ?string
    {
        if (! Manifest::exists()) {
            error("Could not find yolo.yml — run 'yolo init' first.");

            return null;
        }

        $environments = array_keys((array) (Manifest::current()['environments'] ?? []));

        if ($environments === []) {
            error('No environments are declared in yolo.yml.');

            return null;
        }

        if (count($environments) === 1) {
            return (string) $environments[0];
        }

        if (! $this->input->isInteractive()) {
            error('yolo tui needs an environment argument in a non-interactive shell.');

            return null;
        }

        return (string) select(
            label: 'Which environment?',
            options: array_combine($environments, $environments),
        );
    }
}
