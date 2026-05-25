<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Resources\Fargate\EcsCluster;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class RunCommand extends Command
{
    // Today every process runs in the single web container; when queue/scheduler
    // become their own services this becomes per-group.
    protected const CONTAINER = 'web';

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
        // an ordered fallback — scheduler → queue → web. All three collapse into
        // the web container today, so the first two lookups just fall through.
        $groups = ($group = $this->option('group'))
            ? array_map('trim', explode(',', $group))
            : ['scheduler', 'queue', 'web'];

        $fanOut = (bool) $group;

        // Interactive shell can only attach to one task — first running, in order.
        if (! $command) {
            $task = collect($groups)
                ->flatMap(fn (string $group) => Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true)))
                ->first();

            if (! $task) {
                error('No running task found to attach to.');

                return self::FAILURE;
            }

            return $this->exec($cluster, $task, '/bin/sh', interactive: true);
        }

        // One-off command: fan out across all tasks when --group was given,
        // otherwise run on the first group that has a running task (fallback).
        $ran = 0;

        foreach ($groups as $group) {
            $tasks = Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true));

            foreach ($tasks as $task) {
                note(sprintf('%s · %s', $group, $task));
                $this->exec($cluster, $task, $command, interactive: false);
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

    protected function exec(string $cluster, string $task, string $command, bool $interactive): int
    {
        $process = new Process(
            static::executeCommandArgs($cluster, $task, $command, Manifest::get('aws.region'), Helpers::keyedEnv('AWS_PROFILE')),
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
     *
     * @return array<int, string>
     */
    public static function executeCommandArgs(string $cluster, string $task, string $command, string $region, ?string $profile): array
    {
        $args = [
            'aws', 'ecs', 'execute-command',
            '--cluster', $cluster,
            '--task', $task,
            '--container', static::CONTAINER,
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
