<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class RunCommand extends Command
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
            ? array_map('trim', explode(',', $group))
            : ['scheduler', 'queue', 'web'];

        $fanOut = (bool) $group;

        // Interactive shell can only attach to one task — first running, in order.
        if (! $command) {
            foreach ($groups as $group) {
                if ($task = Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true))[0] ?? null) {
                    // The container name is the group (the task-def names its
                    // container after the role), so we exec into the right one.
                    return $this->exec($cluster, $task, '/bin/sh', $group, interactive: true);
                }
            }

            error('No running task found to attach to.');

            return self::FAILURE;
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
            warning(sprintf('No running tasks in: %s', implode(', ', $groups)));
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

        return $process->run(fn ($type, $buffer) => $this->output->write($buffer));
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
