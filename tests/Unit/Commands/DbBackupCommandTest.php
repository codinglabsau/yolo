<?php

declare(strict_types=1);

use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Commands\DbBackupCommand;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
