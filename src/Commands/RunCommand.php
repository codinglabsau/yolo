<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
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

        // An explicit --group fans out across every listed group; the default is
        // an ordered fallback — scheduler → queue → web — so a one-off lands on
        // the first group that has a running task. Each group is its own ECS
        // service now, so a lookup that misses just falls through to the next.
        $groups = ($group = $this->option('group'))
            ? array_map(trim(...), explode(',', (string) $group))
            : ['scheduler', 'queue', 'web'];

        $fanOut = (bool) $group;

        // Interactive shell attaches to one task. With several groups running and
        // no explicit --group, let the operator pick which container to drop into;
        // otherwise take the first running group in order. The container name is the
        // group (the task-def names its container after the role).
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

        // One-off command: fan out across all tasks when --group was given,
        // otherwise run on the first group that has a running task (fallback).
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
            // A one-off that lands on no task ran nowhere — fail loudly so a
            // scripted `yolo run … --command "php artisan migrate --force"` can't
            // report success (exit 0) having done nothing. Mirrors the interactive
            // path above, which already fails when there's no task to attach to.
            error(sprintf('No running tasks in: %s', implode(', ', $groups)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function exec(string $cluster, string $task, string $command, string $container, bool $interactive): int
    {
        $process = new Process(
            static::executeCommandArgs($cluster, $task, $command, $container, Manifest::get('region'), Helpers::keyedEnv('AWS_PROFILE')),
            timeout: null,
        );

        if ($interactive && Process::isTtySupported()) {
            $process->setTty(true);
        }

        return $process->run(fn ($type, string|iterable $buffer) => $this->output->write($buffer));
    }

    /**
     * The `aws ecs execute-command` invocation. Always `--interactive` (the API
     * requires it); the command is `/bin/sh` for a shell or the one-off command.
     * The container is the service group (web/queue/scheduler) — the task-def
     * names its container after the role.
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
