<?php

use Codinglabs\Yolo\Tui\Tui;
use Codinglabs\Yolo\Tui\Screen;
use Codinglabs\Yolo\Tui\Keyboard;
use Codinglabs\Yolo\Tui\Panels\Panel;
use Codinglabs\Yolo\Enums\ServerGroup;
use Symfony\Component\Console\Output\BufferedOutput;

function stubPanel(string $title, string $hotkey): Panel
{
    return new class($title, $hotkey) implements Panel
    {
        public ?string $lastKey = null;

        public function __construct(public string $label, public string $key) {}

        public function title(): string
        {
            return $this->label;
        }

        public function hotkey(): string
        {
            return $this->key;
        }

        public function gather(): void {}

        public function render(int $width, int $height): array
        {
            return ['body:' . $this->label];
        }

        public function hints(): array
        {
            return ['x thing'];
        }

        public function onKey(string $key): void
        {
            $this->lastKey = $key;
        }
    };
}

function tui(array $panels): Tui
{
    $output = new BufferedOutput();

    return new Tui(new Screen($output), new Keyboard(), 'production', $panels, $output);
}

function dotStatus(ServerGroup $group, int $running, int $desired, ?string $rollout = null): array
{
    return ['group' => $group, 'running' => $running, 'desired' => $desired, 'rolloutState' => $rollout];
}

it('navigates tabs with arrows and tab, wrapping at both ends', function (): void {
    $tui = tui([stubPanel('A', 'a'), stubPanel('B', 'b'), stubPanel('C', 'c')]);

    $tui->handleKey('right');
    expect($tui->activeIndex())->toBe(1);

    $tui->handleKey('tab');
    expect($tui->activeIndex())->toBe(2);

    $tui->handleKey('right');
    expect($tui->activeIndex())->toBe(0);

    $tui->handleKey('left');
    expect($tui->activeIndex())->toBe(2);
});

it('jumps to a tab by number and by hotkey', function (): void {
    $tui = tui([stubPanel('A', 'a'), stubPanel('B', 'b'), stubPanel('C', 'c')]);

    $tui->handleKey('3');
    expect($tui->activeIndex())->toBe(2);

    $tui->handleKey('a');
    expect($tui->activeIndex())->toBe(0);
});

it('delegates an unhandled key to the active panel for in-place navigation', function (): void {
    $panels = [stubPanel('A', 'a'), stubPanel('B', 'b')];
    $tui = tui($panels);

    $tui->handleKey('r');

    // The active panel receives the key (to scroll/cycle); the rest are untouched.
    expect($panels[0]->lastKey)->toBe('r')
        ->and($panels[1]->lastKey)->toBeNull();
});

it('quits on q', function (): void {
    $tui = tui([stubPanel('A', 'a')]);

    expect($tui->quitting())->toBeFalse();

    $tui->handleKey('q');

    expect($tui->quitting())->toBeTrue();
});

it('fits the frame to the terminal height with the footer pinned to the last row', function (): void {
    $tui = tui([stubPanel('A', 'a'), stubPanel('B', 'b')]);

    $frame = $tui->frame([dotStatus(ServerGroup::WEB, 1, 1)], 120, 12);

    expect($frame)->toHaveCount(12)
        ->and($frame[2])->toContain('A')          // tab bar (active tab labelled)
        ->and($frame[4])->toBe('body:A')          // panel body opens right below the chrome
        ->and($frame[11])->toContain('q quit');   // footer pinned to the bottom row
});

it('clips an over-long panel body to the height budget', function (): void {
    $tall = new class('Tall', 't') implements Panel
    {
        public function __construct(public string $label, public string $key) {}

        public function title(): string
        {
            return $this->label;
        }

        public function hotkey(): string
        {
            return $this->key;
        }

        public function gather(): void {}

        public function render(int $width, int $height): array
        {
            return array_fill(0, 100, 'row');   // far more than any budget
        }

        public function hints(): array
        {
            return [];
        }

        public function onKey(string $key): void {}
    };

    $frame = (new Tui(new Screen(new BufferedOutput()), new Keyboard(), 'production', [$tall], new BufferedOutput()))
        ->frame([dotStatus(ServerGroup::WEB, 1, 1)], 120, 10);

    expect($frame)->toHaveCount(10)
        ->and($frame[9])->not->toBe('row');   // the footer row survived the clip
});

it('renders the global bar with brand, environment and coloured health dots', function (): void {
    $bar = Tui::globalBar('production', [dotStatus(ServerGroup::WEB, 3, 3), dotStatus(ServerGroup::SCHEDULER, 0, 1)]);

    expect($bar)->toContain('yolo status')
        ->toContain('production')
        ->toContain('<fg=#A3E635>')   // healthy web → lime
        ->toContain('<fg=#FF3B5C>');  // dead scheduler → neon red
});

it('shows the deploy banner on the global bar while a rollout is live', function (): void {
    $bar = Tui::globalBar('production', [dotStatus(ServerGroup::WEB, 2, 3, 'IN_PROGRESS')]);

    expect($bar)->toContain('deploying web 2/3')
        ->toContain('<fg=#FFC83D>');  // gold
});

it('marks the active tab gold and underlined, the rest slate', function (): void {
    $bar = Tui::tabBar([stubPanel('Status', 's'), stubPanel('Logs', 'l')], 0);

    expect($bar)->toContain('options=bold,underscore')
        ->toContain('Status')
        ->toContain('<fg=#6C7A99>Logs</>');
});
