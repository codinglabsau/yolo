<?php

use Codinglabs\Yolo\Tui\Theme;

it('wraps text in a truecolor foreground tag', function (): void {
    expect(Theme::Primary->fg('hi'))->toBe('<fg=#2DD4D4>hi</>');
});

it('renders a bold foreground tag', function (): void {
    expect(Theme::Danger->bold('down'))->toBe('<fg=#FF3B5C;options=bold>down</>');
});

it('renders dark canvas text on a neon background fill', function (): void {
    expect(Theme::Selection->bg('typesense'))->toBe('<fg=#160E2E;bg=#FF2E97>typesense</>');
});

it('exposes a hex value per role', function (): void {
    expect(Theme::Healthy->value)->toBe('#A3E635')
        ->and(Theme::Canvas->value)->toBe('#160E2E');
});
