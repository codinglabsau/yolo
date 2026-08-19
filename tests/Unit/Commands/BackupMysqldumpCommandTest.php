<?php

declare(strict_types=1);

use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\BackupMysqldumpCommand;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('is named backup:mysqldump and registered in the application', function (): void {
    expect((new BackupMysqldumpCommand())->getName())->toBe('backup:mysqldump');

    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];
    expect($commands)->toContain(BackupMysqldumpCommand::class);
});

it('runs at the deployer tier', function (): void {
    // Launching the backup is the same surface as running deploy hooks —
    // ecs:RunTask on the app's own task definitions.
    expect(new BackupMysqldumpCommand())->toBeInstanceOf(DeployerCommand::class);
});

it('refuses when the manifest does not back up MySQL', function (): void {
    // The task role only carries the dumps-prefix grant when backups are on,
    // so a run here could only fail at upload — refuse up front instead.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'mysqldump' => false,
        'tasks' => ['web' => true],
    ]);

    Prompt::interactive(false);
    Prompt::setOutput($promptOutput = new BufferedOutput());

    $command = new BackupMysqldumpCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(1)
        ->and($promptOutput->fetch())->toContain('does not back up MySQL');
});
