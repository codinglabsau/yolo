<?php

use Codinglabs\Yolo\Tui\Splash;

function leadingBlankRows(array $rows): int
{
    $count = 0;

    foreach ($rows as $row) {
        if ($row !== '') {
            break;
        }

        $count++;
    }

    return $count;
}

it('reveals the wordmark left-to-right, splitting cyan→lime when fully shown', function (): void {
    $full = Splash::wordmarkReveal(1.0);

    expect($full)->toHaveCount(count(Splash::WORD));

    foreach ($full as [$tagged, $length]) {
        expect($tagged)->toContain('<fg=#2DD4D4>')   // cyan left half
            ->toContain('<fg=#A3E635>')               // lime right half
            ->and($length)->toBeGreaterThan(0);
    }
});

it('shows no wordmark at the start of the wipe', function (): void {
    [$tagged] = Splash::wordmarkReveal(0.0)[0];

    expect($tagged)->toBe('<fg=#2DD4D4></>');  // an empty cyan run, no lime yet
});

it('animates the rocket: on the pad at 0, lifted off with the tagline at 1', function (): void {
    $start = Splash::frame(0.0, 80);
    $end = Splash::frame(1.0, 80);

    // Constant-height canvas — the in-place repaint must never jump.
    expect($start)->toHaveCount(count($end));

    // The rocket sits low on the pad, then climbs (fewer blank rows above it).
    expect(leadingBlankRows($start))->toBeGreaterThan(leadingBlankRows($end));

    expect(implode("\n", $end))->toContain('deploy · observe · steer')   // tagline lands
        ->toContain('<fg=#A3E635>');                                     // lime wordmark
    expect(implode("\n", $start))->not->toContain('deploy · observe · steer');
});

it('grows the exhaust trail as the rocket climbs', function (): void {
    $trailRows = fn (array $rows): int => count(array_filter($rows, fn (string $row): bool => str_contains($row, '◢') || str_contains($row, '▓') || str_contains($row, '·')));

    expect($trailRows(Splash::frame(1.0, 80)))->toBeGreaterThan($trailRows(Splash::frame(0.0, 80)));
});
