<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

class Yolo
{
    protected Application $app;

    protected array $commands = [
        Commands\InitCommand::class,

        // Machine credentials
        Commands\ConfigureCommand::class,

        // App env files
        Commands\EnvPullCommand::class,
        Commands\EnvPushCommand::class,

        // Environment-shared artefacts
        Commands\EnvironmentManifestPullCommand::class,
        Commands\EnvironmentManifestPushCommand::class,
        Commands\EnvironmentEnvPullCommand::class,
        Commands\EnvironmentEnvPushCommand::class,

        // Build
        Commands\BuildCommand::class,

        // Deploy
        Commands\DeployCommand::class,

        // Rollback
        Commands\RollbackCommand::class,

        // Destroy
        Commands\DestroyCommand::class,
        Commands\DestroyAppCommand::class,
        Commands\DestroyEnvironmentCommand::class,

        // Status
        Commands\StatusCommand::class,
        Commands\StatusAppCommand::class,
        Commands\StatusEnvironmentCommand::class,
        Commands\StatusLogsCommand::class,
        Commands\StatusEventsCommand::class,
        Commands\StatusAlarmsCommand::class,
        Commands\StatusBudgetCommand::class,

        // Exec
        Commands\RunCommand::class,

        // Database
        Commands\DbTunnelCommand::class,
        Commands\DbCutoverCommand::class,
        Commands\DbStatusCommand::class,
        Commands\DbBackupCommand::class,

        // Scale
        Commands\ScaleCommand::class,

        // Access management
        Commands\PermissionsCommand::class,

        // Services
        Commands\ServicesCommand::class,

        // Sync (account → environment → app)
        Commands\SyncCommand::class,
        Commands\SyncAccountCommand::class,
        Commands\SyncEnvironmentCommand::class,
        Commands\SyncAppCommand::class,

        // Audit (account → environment → app)
        Commands\AuditCommand::class,
        Commands\AuditEnvironmentCommand::class,
        Commands\AuditAppCommand::class,
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO, so deploy today 🚀', Helpers::version());

        // Break-glass: registered on the application so every command accepts it; only the
        // tiered commands act on it (see Command).
        $this->app->getDefinition()->addOption(new InputOption(
            'dangerously-skip-permissions',
            null,
            InputOption::VALUE_NONE,
            'Bypass the YOLO permission tier and run as your full AWS identity (uncapped) — bootstrap / break-glass only',
        ));

        $this->registerCommands();
    }

    public function run(): void
    {
        $this->app->run();
    }

    protected function registerCommands(): void
    {
        foreach ($this->commands as $command) {
            $this->app->addCommand(new $command());
        }
    }
}
