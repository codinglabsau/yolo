<?php

namespace Codinglabs\Yolo\TUI;

use Laravel\Prompts\Prompt;
use Chewie\Input\KeyPressListener;
use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\RegistersRenderers;
use Codinglabs\Yolo\TUI\Renderers\DashboardRenderer;

class Dashboard extends Prompt
{
    use CreatesAnAltScreen;
    use RegistersRenderers;

    public array $tabs = [
        [
            'tab' => 'Dashboard',
            'content' => 'Coming soon',
        ],
        [
            'tab' => 'Deployments',
            'content' => 'Coming soon',
        ],
        [
            'tab' => 'Infrastructure',
            'content' => 'Coming soon',
        ],
    ];

    public int $selectedTab = 0;

    public function __construct()
    {
        $this->registerRenderer(DashboardRenderer::class);

        $this->createAltScreen();

        KeyPressListener::for($this)
            ->listenForQuit()
            ->onRight(fn () => $this->selectedTab = min($this->selectedTab + 1, count($this->tabs) - 1))
            ->onLeft(fn () => $this->selectedTab = max($this->selectedTab - 1, 0))
            ->listen();
    }

    public function value(): mixed
    {
        return null;
    }
}
