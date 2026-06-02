<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;
use Symfony\Component\Console\Application;

class Yolo
{
    protected Application $app;

    protected array $commands = [
        Commands\InitCommand::class,

        // Environments
        Commands\EnvPullCommand::class,
        Commands\EnvPushCommand::class,

        // Build
        Commands\BuildCommand::class,

        // Deploy
        Commands\DeployCommand::class,

        // Exec
        Commands\RunCommand::class,

        // Scale
        Commands\ScaleCommand::class,

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
