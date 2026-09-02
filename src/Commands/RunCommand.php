<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

class RunCommand extends Command implements DeployerCommand
{
    protected function configure(): void
    {
        $this
            ->setName('run')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Run a one-off command instead of opening an interactive shell')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Comma-separated task groups to fan the command out across (e.g. web,queue)')
            ->setDescription('Open a shell or run a command in a running container via ECS Exec');
    }

    public function handle(): int
    {
        if (! (new ExecutableFinder())->find('session-manager-plugin')) {
            error("session-manager-plugin isn't installed — run `yolo init` (or see the AWS docs) before using `yolo run`.");

            return self::FAILURE;
        }

        $cluster = (new EcsCluster())->name();
        $command = $this->option('command');

        // An explicit --group fans out across every listed group; the default is an
        // ordered fallback so a one-off lands on the first group with a running task.
        $groups = ($group = $this->option('group'))
            ? array_map(trim(...), explode(',', (string) $group))
            : ['scheduler', 'queue', 'web'];

        $fanOut = (bool) $group;

        // The container name is the group — the task-def names its container after the role.
        if (! $command) {
            $running = [];

            foreach ($groups as $group) {
                if (($task = Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true))[0] ?? null) !== null) {
                    $running[$group] = $task;
                }
            }

            if ($running === []) {
                error('No running task found to attach to.');

                return self::FAILURE;
            }

            $group = (! $fanOut && count($running) > 1 && $this->input->isInteractive())
                ? (string) select(label: 'Open a shell in which group?', options: array_combine(array_keys($running), array_keys($running)))
                : array_key_first($running);

            return $this->exec($cluster, $running[$group], '/bin/sh', $group, interactive: true);
        }

        $ran = 0;

        foreach ($groups as $group) {
            $tasks = Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true));

            foreach ($tasks as $task) {
                note(sprintf('%s · %s', $group, $task));
                $this->exec($cluster, $task, $command, $group, interactive: false);
                $ran++;
            }

            if ($tasks && ! $fanOut) {
                break;
            }
        }

        if ($ran === 0) {
            // Fail loudly so a scripted one-off can't report success having done nothing.
            error(sprintf('No running tasks in: %s', implode(', ', $groups)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function exec(string $cluster, string $task, string $command, string $container, bool $interactive): int
    {
        // ecs:ExecuteCommand lives on the deployer role, not the operator's own
        // identity — see Command::subprocessEnv().
        $process = new Process(
            static::executeCommandArgs(
                $cluster,
                $task,
                $interactive ? $command : static::encodeCommand($command),
                $container,
                Manifest::get('region'),
                $this->subprocessProfile(),
            ),
            env: $this->subprocessEnv(),
            timeout: null,
        );

        if ($interactive && Process::isTtySupported()) {
            $process->setTty(true);
        } else {
            // The exec API is interactive-only, so the plugin always runs a stdin
            // pump; a closed stdin surfaces as a spurious "Cannot perform start
            // session: EOF". An open, never-written stream keeps it quiet.
            $process->setInput(new InputStream());
        }

        return $process->run(fn ($type, string|iterable $buffer) => $this->output->write($buffer));
    }

    /**
     * The SSM agent does NOT run `--command` through a shell: it shellwords-splits
     * the string (quotes grouped, unquoted backslashes consumed) and execs argv
     * directly, so shell syntax is inert — a bare `echo <b64> | base64 -d | sh`
     * prints six literal arguments and exits 0. Hence an explicit `sh -c` argv, and
     * base64 so the operator's own quotes and backslashes never meet the tokeniser.
     * Never applied to the interactive shell — its stdin must stay the terminal.
     */
    public static function encodeCommand(string $command): string
    {
        return sprintf("sh -c 'echo %s | base64 -d | sh'", base64_encode($command));
    }

    /**
     * Always `--interactive` — the API requires it.
     *
     * @return array<int, string>
     */
    public static function executeCommandArgs(string $cluster, string $task, string $command, string $container, string $region, ?string $profile): array
    {
        $args = [
            'aws', 'ecs', 'execute-command',
            '--cluster', $cluster,
            '--task', $task,
            '--container', $container,
            '--interactive',
            '--command', $command,
            '--region', $region,
        ];

        if ($profile) {
            $args[] = '--profile';
            $args[] = $profile;
        }

        return $args;
    }
}
