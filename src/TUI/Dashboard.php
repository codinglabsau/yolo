<?php

namespace Codinglabs\Yolo\TUI;

use Laravel\Prompts\Prompt;
use Chewie\Input\KeyPressListener;
use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\RegistersRenderers;
use Codinglabs\Yolo\TUI\Renderers\DashboardRenderer;

class Dashboard extends Prompt
{
    use RegistersRenderers;
    use CreatesAnAltScreen;

    public array $tabs = [
        [
            'tab' => 'Dashboard',
            'content' => "Hello! I'm Joe, a dedicated software engineer with a passion for crafting clean, efficient, and user-friendly applications. With a background in computer science and years of experience in the tech industry, I thrive on collaborating with teams to turn ideas into functional software solutions.\n\nI believe that innovation drives progress, and I am constantly exploring new technologies to stay ahead of the curve. When I'm not coding, you can find me exploring the intersection of art and technology or enjoying the great outdoors. Let's connect and create something amazing together!",
        ],
        [
            'tab' => 'Deployments',
            'content' => "Coming soon",
        ],
        [
            'tab' => 'Infrastructure',
            'content' => "Coming soon",
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
