<?php

namespace Codinglabs\Yolo\Tui;

use Codinglabs\Yolo\Tui\Panels\Panel;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The dashboard shell behind `yolo status` — a tab host over the live environment.
 * It owns the poll/redraw loop, the always-on global health bar, the tab bar, the
 * footer, and key routing; each Panel owns its own tab body. Navigation only — tabs
 * and scrolling; the dashboard is read-only, so a keypress never triggers an action.
 *
 * The chrome (global bar, tab bar, footer, frame, key routing) is pure and tested;
 * only the raw terminal loop is @codeCoverageIgnore.
 */
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

    /**
     * The live dashboard: enter the alternate screen + raw mode, optionally play
     * the splash over the first gather, then poll/redraw and route keys until quit.
     *
     * @codeCoverageIgnore the live loop — raw terminal I/O + timing, verified by hand
     */
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

                // Wait out the poll window, but repaint promptly after any key —
                // a key may switch tabs or set quit (which the outer loop checks).
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
     * Compose the whole frame, fitted to exactly $height rows: the global health
     * bar + tab bar above, the active panel body in the remaining budget, and the
     * footer pinned to the bottom row. The body gets only the rows left after the
     * chrome, so a long panel (logs) clips/scrolls instead of overflowing.
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

    /**
     * Route a keypress: quit, tab navigation (arrows/tab), number and letter
     * hotkeys jump tabs; anything else delegates to the active panel for in-place
     * navigation (scrolling, group cycling). Read-only — never dispatches an action.
     */
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
     * The always-on top line: brand + environment on the left, and either the
     * live rollout banner (when deploying) or the per-group health dots.
     *
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
     * One coloured dot per group — lime when healthy, red when down, gold when
     * mid-roll, slate when idle at zero.
     *
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
     * The tab bar — the active tab gold + underlined, the rest slate.
     *
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

    /** The footer hints — the active panel's, then the global navigation keys. */
    public static function footer(Panel $panel, int $count): string
    {
        $hints = [...$panel->hints(), '◂ ▸ tabs', '1-' . $count . ' jump', 'q quit'];

        return '  ' . Theme::Muted->fg(implode('   ', $hints));
    }
}
