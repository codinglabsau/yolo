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
        Commands\CommandCommand::class,

        // Sync
        Commands\SyncCommand::class,
        Commands\SyncNetworkCommand::class,
        Commands\SyncStorageCommand::class,
        Commands\SyncSoloCommand::class,
        Commands\SyncMultitenancyTenantsCommand::class,
        Commands\SyncMultitenancyLandlordCommand::class,
        Commands\SyncComputeCommand::class,
        Commands\SyncIamCommand::class,
        Commands\SyncLoggingCommand::class,
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO, so deploy today 🚀', '1.0.0-alpha');

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
