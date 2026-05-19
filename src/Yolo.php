<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;
use Symfony\Component\Console\Application;

class Yolo
{
    protected Application $app;

    /**
     * Commands registered with the YOLO CLI.
     *
     * v1 is in active development — commands land incrementally as MVP work ships.
     */
    protected array $commands = [
        //
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO — Fargate-first deploys for Laravel', '1.0.0-alpha');

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
