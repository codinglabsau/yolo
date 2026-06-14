<?php

use Codinglabs\Yolo\Tui\Splash;

it('splits each wordmark line cyan→lime', function (): void {
    $wordmark = Splash::wordmark();

    expect($wordmark)->toHaveCount(count(Splash::WORD));

    foreach ($wordmark as [$tagged, $length]) {
        expect($tagged)->toContain('<fg=#2DD4D4>')   // cyan left half
            ->toContain('<fg=#A3E635>')               // lime right half
            ->and($length)->toBeGreaterThan(0);
    }
});

it('composes a centred splash frame with the wordmark, flame and tagline', function (): void {
    $lines = Splash::lines(80);
    $joined = implode("\n", $lines);

    expect($joined)->toContain('deploy · observe · steer')   // tagline
        ->toContain('<fg=#FFC83D>')                          // gold flame
        ->toContain('<fg=#2DD4D4>')                          // cyan wordmark half
        ->and($lines[1])->toStartWith(' ');                  // rows are centre-padded
});
