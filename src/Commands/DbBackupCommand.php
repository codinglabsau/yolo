<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\ProcessCommands;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

/**
 * Run the database backup on demand: launch the same in-container executor the
 * generated crontab schedules (ProcessCommands::databaseBackup — identical
 * invocation, so the two can't drift) as a one-off Fargate task, and stream
 * its output back until it stops. The dump must execute inside the VPC — only
 * a task has network locality to the database — so this command's job is
 * launching and watching, never dumping.
 */
class DbBackupCommand extends Command implements DeployerCommand
{
    /**
     * Task-level CPU/memory for the one-off backup task, overriding the deploy
     * group's task definition — the same bump the deploy hooks get, for the
     * same reason: `zstd -T0` is CPU-hungry and a dedicated short-lived task
     * shouldn't inherit a thin scheduler/queue sizing. Must stay a valid
     * Fargate pair.
     */
    protected const string BACKUP_TASK_CPU = '1024';

    protected const string BACKUP_TASK_MEMORY = '2048';

    /** How long to watch a run before giving up — the task itself keeps
     * running; this only bounds the tail. */
    protected const int WATCH_TIMEOUT_SECONDS = 2 * 3600;

    protected function configure(): void
    {
        $this
            ->setName('backup:database')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Run the scheduled database backup now, as a one-off task with streamed output');
    }

    public function handle(): int
    {
        if (! Manifest::backsUpDatabases()) {
            error('This app does not back up its databases — `backups` is off in the manifest (or cron runs nowhere to host it), so the task role has no grant to upload a dump.');

            return self::FAILURE;
        }

        // The same placement rules as the one-off deploy hooks: the management
        // tier's task definition, with the container override matched by the
        // group's container name (a mismatched name would silently boot the
        // role's default process instead of the backup).
        $group = Manifest::deployGroup();
        $cluster = (new EcsCluster())->name();

        intro('Launching the backup task');
        note('Same invocation as the scheduled crontab entry: ' . implode(' ', ProcessCommands::databaseBackup()));

        $run = Aws::ecs()->runTask([
            'cluster' => $cluster,
            'taskDefinition' => (new EcsService($group))->name(),
            'launchType' => 'FARGATE',
            'count' => 1,
            'startedBy' => 'yolo-backup',
            'overrides' => [
                'cpu' => self::BACKUP_TASK_CPU,
                'memory' => self::BACKUP_TASK_MEMORY,
                'containerOverrides' => [
                    [
                        'name' => $group->value,
                        'command' => ProcessCommands::databaseBackup(),
                    ],
                ],
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => PublicSubnet::ids(),
                    'securityGroups' => [(new EcsTaskSecurityGroup())->arn()],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
        ]);

        if (empty($run['tasks'])) {
            throw new IntegrityCheckException(sprintf(
                'ECS RunTask returned no tasks. Failures: %s',
                json_encode($run['failures'] ?? [])
            ));
        }

        $taskArn = (string) $run['tasks'][0]['taskArn'];
        $taskId = substr($taskArn, strrpos($taskArn, '/') + 1);

        note(sprintf('Task %s started — streaming its output.', $taskId));

        $exitCode = $this->watch($cluster, $taskArn, $group->value, $taskId);

        if ($exitCode !== 0) {
            error(sprintf('Backup task exited with code %s — see the output above.', $exitCode ?? 'unknown'));

            return self::FAILURE;
        }

        info('Backup task completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Poll the task until it stops, printing each new log line as it lands —
     * the awslogs stream is named {group}/{group}/{taskId}. Returns the
     * container's exit code (null when ECS reports none, e.g. a task that
     * never started its container).
     */
    protected function watch(string $cluster, string $taskArn, string $containerName, string $taskId): ?int
    {
        $logGroup = (new TaskLogGroup())->name();
        $stream = sprintf('%s/%s/%s', $containerName, $containerName, $taskId);
        $lastTimestamp = 0;
        $deadline = time() + self::WATCH_TIMEOUT_SECONDS;

        while (true) {
            $task = Aws::ecs()->describeTasks([
                'cluster' => $cluster,
                'tasks' => [$taskArn],
            ])['tasks'][0] ?? null;

            $lastTimestamp = $this->printNewEvents($logGroup, $stream, $lastTimestamp);

            if (($task['lastStatus'] ?? null) === 'STOPPED') {
                // One final read — the last lines can land after the stop.
                $this->printNewEvents($logGroup, $stream, $lastTimestamp);

                return $task['containers'][0]['exitCode'] ?? null;
            }

            if (time() >= $deadline) {
                error('Stopped watching after two hours — the task is still running; its output continues in CloudWatch.');

                return null;
            }

            sleep(5);
        }
    }

    protected function printNewEvents(string $logGroup, string $stream, int $lastTimestamp): int
    {
        try {
            $events = Aws::cloudWatchLogs()->filterLogEvents([
                'logGroupName' => $logGroup,
                'logStreamNames' => [$stream],
                'startTime' => $lastTimestamp + 1,
            ])['events'] ?? [];
        } catch (AwsException $e) {
            // The stream appears only once the container writes its first line.
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return $lastTimestamp;
            }

            throw $e;
        }

        foreach ($events as $event) {
            $this->output->writeln('  ' . rtrim((string) $event['message']));
            $lastTimestamp = max($lastTimestamp, (int) $event['timestamp']);
        }

        return $lastTimestamp;
    }
}
