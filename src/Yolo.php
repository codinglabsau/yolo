<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;
use Symfony\Component\Console\Application;

class Yolo
{
    protected Application $app;

    protected array $commands = [
        Commands\InitCommand::class,

        // General purpose
        Commands\CommandCommand::class,
        Commands\Ec2ListCommand::class,

        // Build & deploy
        Commands\BuildCommand::class,
        Commands\StopCommand::class,
        Commands\DeployCommand::class,
        Commands\StartCommand::class,

        // Environments
        Commands\EnvPullCommand::class,
        Commands\EnvPushCommand::class,

        // Images
        Commands\ImageCreateCommand::class,
        Commands\ImageListCommand::class,
        Commands\ImagePrepareCommand::class,

        // Sync
        Commands\SyncCommand::class,
        Commands\SyncNetworkCommand::class,
        Commands\SyncStorageCommand::class,
        Commands\SyncStandaloneCommand::class,
        Commands\SyncMultitenancyTenantsCommand::class,
        Commands\SyncMultitenancyLandlordCommand::class,
        Commands\SyncComputeCommand::class,
        Commands\SyncCiCommand::class,
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO, so deploy today 🚀', '1.0.0');

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
