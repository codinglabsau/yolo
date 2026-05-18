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
     * v2 is in active development — commands will be added as MVP issues land.
     * See https://linear.app/codinglabsau/project/yolo-v2-f26af789f353 for the roadmap.
     */
    protected array $commands = [
        // Empty for now — v2 commands land via the MVP milestone.
    ];

    public function __construct()
    {
        Container::setInstance(new Container());

        $this->app = new Application('YOLO v2 — Fargate-first deploys for Laravel 🚀', '2.0.0-alpha');

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
