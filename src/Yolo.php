<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;
use Symfony\Component\Console\Application;

class Yolo
{
    protected Application $app;

    protected array $commands = [
        Commands\InitCommand::class,

        // AWS
        Commands\AmiCreateCommand::class,
        Commands\AmiListCommand::class,
        Commands\PrepareCommand::class,
        Commands\CommandCommand::class,
        Commands\Ec2ListCommand::class,

        // Build
        Commands\BuildCommand::class,

        // Deploy
        Commands\StopCommand::class,
        Commands\DeployCommand::class,
        Commands\StartCommand::class,

        // Environments
        Commands\EnvPullCommand::class,
        Commands\EnvPushCommand::class,

        // Sync
        Commands\SyncCommand::class,
        Commands\SyncCiCommand::class,
        Commands\SyncComputeCommand::class,
        Commands\SyncNetworkCommand::class,
        Commands\SyncMultitenancyLandlordCommand::class,
        Commands\SyncMultitenancyTenantsCommand::class,
        Commands\SyncS3Command::class,
        Commands\SyncPermissionsCommand::class,
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
