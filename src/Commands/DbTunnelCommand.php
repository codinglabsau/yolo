<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

use function Laravel\Prompts\note;
use function Laravel\Prompts\error;

/**
 * The laptop path to a database in the private subnet tier, which has no public
 * endpoint by design. The task is the SSM target, so the session rides the same
 * ECS Exec plumbing `yolo run` uses (`enableExecuteCommand` + the `ssmmessages`
 * channels on the task role).
 */
class DbTunnelCommand extends Command implements ReadOnlyCommand
{
    protected function configure(): void
    {
        $this
            ->setName('db:tunnel')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The local port to listen on', '13306')
            ->setDescription('Port-forward the manifest-declared database to localhost through a running task');
    }

    public function handle(): int
    {
        if (! (new ExecutableFinder())->find('session-manager-plugin')) {
            error("session-manager-plugin isn't installed — run `yolo init` (or see the AWS docs) before using `yolo db:tunnel`.");

            return self::FAILURE;
        }

        if (($database = $this->database()) === null) {
            return self::FAILURE;
        }

        [$host, $remotePort] = $database;

        $cluster = (new EcsCluster())->name();

        if (($running = $this->runningTask($cluster)) === null) {
            error('No running task to tunnel through — deploy the app first.');

            return self::FAILURE;
        }

        [$group, $taskArn] = $running;

        if (($target = $this->sessionTarget($cluster, $taskArn, $group)) === null) {
            return self::FAILURE;
        }

        $localPort = (string) $this->option('port');

        note(sprintf('Tunnel: 127.0.0.1:%s → %s:%d (via %s) — Ctrl-C to close.', $localPort, $host, $remotePort, Str::afterLast($taskArn, '/')));

        $process = new Process(
            static::startSessionArgs($target, $host, $remotePort, $localPort, Manifest::get('region'), $this->subprocessProfile()),
            env: $this->subprocessEnv(),
            timeout: null,
        );

        return $process->run(fn ($type, string|iterable $buffer) => $this->output->write($buffer));
    }

    /**
     * A cluster forwards to its writer endpoint so the tunnel follows failovers; the
     * port comes off the same record so a non-default port needs no telling.
     *
     * @return array{0: string, 1: int}|null [host, port]
     */
    protected function database(): ?array
    {
        try {
            $target = Rds::target();
        } catch (ResourceDoesNotExistException $exception) {
            error($exception->getMessage());

            return null;
        } catch (RdsException $exception) {
            error(sprintf('Could not classify "%s": %s.', (string) Manifest::database(), $exception->getAwsErrorCode() ?? 'unknown error'));

            return null;
        }

        if ($target === null) {
            error('No `database:` declared in the manifest — nothing to tunnel to.');

            return null;
        }

        try {
            $record = $target['cluster'] ? Rds::cluster($target['identifier']) : Rds::instance($target['identifier']);
        } catch (RdsException $exception) {
            error(sprintf('Could not resolve the endpoint for "%s": %s.', $target['identifier'], $exception->getAwsErrorCode() ?? 'unknown error'));

            return null;
        }

        $endpoint = $record === null ? null : ($target['cluster']
            ? ($record['Endpoint'] ?? null)
            : ($record['Endpoint']['Address'] ?? null));

        if ($endpoint === null || $record === null) {
            error(sprintf('Could not resolve the endpoint for "%s" — the database reports no endpoint yet.', $target['identifier']));

            return null;
        }

        $port = Rds::portFromRecord($record, $target['cluster']);

        if ($port === null) {
            error(sprintf('Could not resolve the port for "%s" — the database reports no port yet.', $target['identifier']));

            return null;
        }

        return [$endpoint, $port];
    }

    /**
     * Any group shares the task security group's database grant, so a web-less
     * worker app tunnels through its queue/scheduler task.
     *
     * @return array{0: string, 1: string}|null [group, taskArn]
     */
    protected function runningTask(string $cluster): ?array
    {
        foreach (Manifest::serverGroups() as $group) {
            $taskArn = Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true))[0] ?? null;

            if ($taskArn !== null) {
                return [$group->value, $taskArn];
            }
        }

        return null;
    }

    /**
     * `ecs:{cluster}_{taskId}_{runtimeId}` — SSM addresses the container agent, not
     * the task.
     */
    protected function sessionTarget(string $cluster, string $taskArn, string $group): ?string
    {
        $task = Aws::ecs()->describeTasks([
            'cluster' => $cluster,
            'tasks' => [$taskArn],
        ])['tasks'][0] ?? null;

        $runtimeId = collect($task['containers'] ?? [])->firstWhere('name', $group)['runtimeId'] ?? null;

        if ($runtimeId === null) {
            error(sprintf('Could not resolve the %s container\'s runtime id — is the task still starting?', $group));

            return null;
        }

        return sprintf('ecs:%s_%s_%s', $cluster, Str::afterLast($taskArn, '/'), $runtimeId);
    }

    /**
     * @return array<int, string>
     */
    public static function startSessionArgs(string $target, string $host, int $remotePort, string $localPort, string $region, ?string $profile): array
    {
        $args = [
            'aws', 'ssm', 'start-session',
            '--target', $target,
            '--document-name', 'AWS-StartPortForwardingSessionToRemoteHost',
            '--parameters', (string) json_encode([
                'host' => [$host],
                'portNumber' => [(string) $remotePort],
                'localPortNumber' => [$localPort],
            ]),
            '--region', $region,
        ];

        if ($profile) {
            $args[] = '--profile';
            $args[] = $profile;
        }

        return $args;
    }
}
