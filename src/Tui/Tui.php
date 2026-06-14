<?php

namespace Codinglabs\Yolo\Tui;

use Closure;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Tui\Panels\Panel;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;

/**
 * The dashboard shell — the status command's live-render loop, generalised into a
 * tab host. It owns the poll/redraw loop, the always-on global health bar, the
 * tab bar, the footer, and key routing; each Panel owns its own tab body. When a
 * panel returns a modal closure for a key, the loop pauses, drops to cooked mode
 * so Laravel Prompts can take the screen, then resumes.
 *
 * The chrome (global bar, tab bar, footer, frame, key routing) is pure and
 * tested; only the raw terminal loop and the modal handoff are @codeCoverageIgnore.
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
                (new Splash())->play($this->screen, $this->keyboard, fn () => $this->panels[$this->active]->gather());
            }

            while (! $this->quit) {
                $statuses = static::gatherServiceStatuses(withLoad: true);
                $this->panels[$this->active]->gather();
                $this->screen->paint($this->frame($statuses, $this->screen->width()));

                $deadline = microtime(true) + 3.0;

                // Wait out the poll window, but repaint promptly after any key —
                // a key may switch tabs or set quit (which the outer loop checks).
                while (microtime(true) < $deadline) {
                    $key = $this->keyboard->read();

                    if ($key !== null) {
                        $modal = $this->handleKey($key);

                        if ($modal instanceof Closure) {
                            $this->runModal($modal);
                        }

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
     * Hand the screen to a Laravel Prompts modal: leave the alternate buffer and
     * raw mode so Prompts draws and reads normally, run it, then re-enter.
     *
     * @codeCoverageIgnore modal handoff — terminal mode switching
     */
    protected function runModal(Closure $modal): void
    {
        $this->screen->close();
        $this->keyboard->restore();

        Prompt::interactive(true);
        $modal();

        $this->keyboard->rawMode();
        $this->screen->open();
    }

    /**
     * Compose the whole frame — global health bar, tab bar, active panel body, footer.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    public function frame(array $statuses, int $width): array
    {
        $panel = $this->panels[$this->active];

        return [
            '',
            self::globalBar($this->environment, $statuses),
            self::tabBar($this->panels, $this->active),
            '',
            ...$panel->render($width),
            '',
            self::footer($panel, count($this->panels)),
        ];
    }

    /**
     * Route a keypress: quit, tab navigation (arrows/tab), number and letter
     * hotkeys jump tabs; anything else delegates to the active panel, which may
     * return a modal closure for the loop to run.
     */
    public function handleKey(string $key): ?Closure
    {
        $count = count($this->panels);

        if ($key === 'q' || $key === 'ctrl-c') {
            $this->quit = true;

            return null;
        }

        if ($key === 'right' || $key === 'tab') {
            $this->active = ($this->active + 1) % $count;

            return null;
        }

        if ($key === 'left') {
            $this->active = ($this->active - 1 + $count) % $count;

            return null;
        }

        if (ctype_digit($key) && isset($this->panels[(int) $key - 1])) {
            $this->active = (int) $key - 1;

            return null;
        }

        foreach ($this->panels as $index => $panel) {
            if ($panel->hotkey() === $key) {
                $this->active = $index;

                return null;
            }
        }

        return $this->panels[$this->active]->onKey($key);
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
        $left = Theme::Primary->bold('yolo tui') . Theme::Muted->fg(' · ' . $environment);

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
