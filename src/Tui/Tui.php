<?php

namespace Codinglabs\Yolo\Tui;

use Codinglabs\Yolo\Tui\Panels\Panel;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/** Read-only tab host: a keypress only navigates, never triggers an action. */
class Tui
{
    use RendersServiceStatus;

    protected bool $quit = false;

    /**
     * @param  array<int, Panel>  $panels
     */
    public function __construct(
        protected Screen $screen,
        protected Keyboard $keyboard,
        protected string $environment,
        protected array $panels,
        public OutputInterface $output,
        protected int $active = 0,
        protected bool $splash = true,
    ) {}

    /** @codeCoverageIgnore raw terminal I/O + timing, verified by hand */
    public function run(): int
    {
        $this->keyboard->rawMode();
        $this->screen->open();

        try {
            if ($this->splash) {
                (new Splash())->play(
                    $this->screen,
                    $this->keyboard,
                    fn () => $this->panels[$this->active]->gather(),
                    'connecting to ' . $this->environment . '…',
                );
            }

            while (! $this->quit) {
                $statuses = static::gatherServiceStatuses(withLoad: true);
                $this->panels[$this->active]->gather();
                $this->screen->paint($this->frame($statuses, $this->screen->width(), $this->screen->height()));

                $deadline = microtime(true) + 3.0;

                // Repaint promptly after any key rather than waiting out the poll window.
                while (microtime(true) < $deadline) {
                    $key = $this->keyboard->read();

                    if ($key !== null) {
                        $this->handleKey($key);

                        break;
                    }

                    usleep(40_000);
                }
            }
        } finally {
            $this->screen->close();
            $this->keyboard->restore();
        }

        return 0;
    }

    /**
     * The body only gets the rows left after the chrome, so a long panel clips/scrolls instead of pushing the footer off.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    public function frame(array $statuses, int $width, int $height): array
    {
        $panel = $this->panels[$this->active];

        $top = [
            '',
            self::globalBar($this->environment, $statuses),
            self::tabBar($this->panels, $this->active),
            '',
        ];

        $bottom = [
            '',
            self::footer($panel, count($this->panels)),
        ];

        $budget = Layout::bodyBudget(count($top), count($bottom), $height);

        return Layout::fit($top, $panel->render($width, $budget), $bottom, $height);
    }

    public function handleKey(string $key): void
    {
        $count = count($this->panels);

        if ($key === 'q' || $key === 'ctrl-c') {
            $this->quit = true;

            return;
        }

        if ($key === 'right' || $key === 'tab') {
            $this->active = ($this->active + 1) % $count;

            return;
        }

        if ($key === 'left') {
            $this->active = ($this->active - 1 + $count) % $count;

            return;
        }

        if (ctype_digit($key) && isset($this->panels[(int) $key - 1])) {
            $this->active = (int) $key - 1;

            return;
        }

        foreach ($this->panels as $index => $panel) {
            if ($panel->hotkey() === $key) {
                $this->active = $index;

                return;
            }
        }

        $this->panels[$this->active]->onKey($key);
    }

    public function activeIndex(): int
    {
        return $this->active;
    }

    public function quitting(): bool
    {
        return $this->quit;
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function globalBar(string $environment, array $statuses): string
    {
        $left = Theme::Primary->bold('yolo status') . Theme::Muted->fg(' · ' . $environment);

        $banner = DeployObserver::banner($statuses);

        $right = $banner !== null
            ? Theme::Active->fg('⟳ ' . $banner)
            : self::healthDots($statuses);

        return '  ' . $left . '    ' . $right;
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function healthDots(array $statuses): string
    {
        return implode('   ', array_map(self::dotFor(...), $statuses));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected static function dotFor(array $status): string
    {
        $running = (int) ($status['running'] ?? 0);
        $desired = (int) ($status['desired'] ?? 0);
        $label = sprintf('%s %d/%d', $status['group']->value, $running, $desired);

        return match (true) {
            $desired === 0 => Theme::Muted->fg('· ' . $label),
            $running >= $desired => Theme::Healthy->fg('● ' . $label),
            $running === 0 => Theme::Danger->fg('✗ ' . $label),
            default => Theme::Warning->fg('◐ ' . $label),
        };
    }

    /**
     * @param  array<int, Panel>  $panels
     */
    public static function tabBar(array $panels, int $active): string
    {
        $tabs = [];

        foreach ($panels as $index => $panel) {
            $tabs[] = $index === $active
                ? sprintf('<fg=%s;options=bold,underscore>%s</>', Theme::Active->value, $panel->title())
                : Theme::Muted->fg($panel->title());
        }

        return '  ' . implode('   ', $tabs);
    }

    public static function footer(Panel $panel, int $count): string
    {
        $hints = [...$panel->hints(), '◂ ▸ tabs', '1-' . $count . ' jump', 'q quit'];

        return '  ' . Theme::Muted->fg(implode('   ', $hints));
    }
}
