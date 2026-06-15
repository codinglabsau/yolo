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

        // App env files
        Commands\EnvPullCommand::class,
        Commands\EnvPushCommand::class,

        // Environment-shared artefacts (the env manifest + env-shared .env in
        // the env config bucket)
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

        // Status (scope-grouped: app-tier `status`/`status:app`, env-tier roll-up,
        // and the incident read surfaces — logs / events / alarms)
        Commands\StatusCommand::class,
        Commands\StatusAppCommand::class,
        Commands\StatusEnvironmentCommand::class,
        Commands\StatusLogsCommand::class,
        Commands\StatusEventsCommand::class,
        Commands\StatusAlarmsCommand::class,
        Commands\StatusBudgetCommand::class,

        // Exec
        Commands\RunCommand::class,

        // Scale
        Commands\ScaleCommand::class,

        // Services (the env service gate)
        Commands\ServicesCommand::class,

        // Interactive dashboard
        Commands\TuiCommand::class,

        // Sync (scope-grouped: account → environment → app, orchestrated by `sync`)
        Commands\SyncCommand::class,
        Commands\SyncAccountCommand::class,
        Commands\SyncEnvironmentCommand::class,
        Commands\SyncAppCommand::class,

        // Audit (scope-grouped: account → environment → app, orchestrated by `audit`)
        Commands\AuditCommand::class,
        Commands\AuditEnvironmentCommand::class,
        Commands\AuditAppCommand::class,
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO, so deploy today 🚀', Helpers::version());

        // Global break-glass flag: skip the per-command tier capping and run on the
        // developer's full profile identity. Registered on the application so every
        // command accepts it; only the tiered commands act on it (see Command).
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
            $this->app->add(new $command());
        }
    }
}
