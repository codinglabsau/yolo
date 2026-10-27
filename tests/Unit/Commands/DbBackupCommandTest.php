<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Commands\DbBackupCommand;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('is named backup:database and registered in the application', function (): void {
    expect((new DbBackupCommand())->getName())->toBe('backup:database');

    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];
    expect($commands)->toContain(DbBackupCommand::class);
});

it('runs at the deployer tier', function (): void {
    // Launching the backup is the same surface as running deploy hooks —
    // ecs:RunTask on the app's own task definitions.
    expect(new DbBackupCommand())->toBeInstanceOf(DeployerCommand::class);
});

it('refuses when the manifest has not opted into backups', function (): void {
    // The task role only carries the dumps-prefix grant when backups are on,
    // so a run here could only fail at upload — refuse up front instead. The
    // beforeEach manifest is the default (no backups key), which is off.
    Prompt::interactive(false);
    Prompt::setOutput($promptOutput = new BufferedOutput());

    $command = new DbBackupCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(1)
        ->and($promptOutput->fetch())->toContain('does not back up its databases');
});

it('launches the task with the baked invocation, reader host included', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'backups' => true,
        'database' => 'my-cluster',
        'tasks' => ['web' => true],
    ]);

    $rds = $ec2 = $ecs = [];
    bindMockRdsClient(['DescribeDBClusters' => auroraCluster(readers: 1)], $rds);
    bindMockEc2Client([
        'DescribeSubnets' => new Result(['Subnets' => [['SubnetId' => 'subnet-1']]]),
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [['GroupName' => (new EcsTaskSecurityGroup())->name(), 'GroupId' => 'sg-1']]]),
    ], $ec2);
    bindRoutedEcsClient([
        'RunTask' => new Result(['tasks' => [['taskArn' => 'arn:aws:ecs:ap-southeast-2:111111111111:task/yolo-testing-my-app/abc123']]]),
        'DescribeTasks' => new Result(['tasks' => [['lastStatus' => 'STOPPED', 'containers' => [['exitCode' => 0]]]]]),
    ], $ecs);
    Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => new MockHandler([new Result(['events' => []]), new Result(['events' => []])]),
    ]));

    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());

    $command = new DbBackupCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(0)
        ->and(collect($ecs)->firstWhere('name', 'RunTask')['args']['overrides']['containerOverrides'][0]['command'])
        ->toContain('--host=my-cluster.cluster-ro-abc123.ap-southeast-2.rds.amazonaws.com');
});
