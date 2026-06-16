<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Panels\AlarmsPanel;

it('is the Alarms tab on the a hotkey', function (): void {
    $panel = new AlarmsPanel();

    expect($panel->title())->toBe('Alarms')
        ->and($panel->hotkey())->toBe('a');
});

it('truncates a long alarm reason so the row never exceeds the width and wraps the frame', function (): void {
    $reason = str_repeat('Threshold Crossed: no datapoints were received and were treated as [NonBreaching]. ', 6);

    $row = AlarmsPanel::alarmRow(['name' => 'yolo-production-codinglabs-web-worker-saturation', 'state' => 'OK', 'reason' => $reason], 80);

    $visible = preg_replace('/<[^>]+>/', '', $row);   // strip the Symfony colour tags

    expect(mb_strlen((string) $visible))->toBeLessThanOrEqual(80)
        ->and($visible)->toContain('OK')
        ->and($visible)->toContain('web-worker-saturation')   // the name survives
        ->and($visible)->not->toContain($reason);             // the full reason was cut
});

it('keeps a short alarm intact, badge then name then reason', function (): void {
    $row = AlarmsPanel::alarmRow(['name' => 'yolo-x-queue-depth', 'state' => 'ALARM', 'reason' => 'too deep'], 120);

    $visible = preg_replace('/<[^>]+>/', '', $row);

    expect($visible)->toContain('ALARM')
        ->toContain('yolo-x-queue-depth')
        ->toContain('— too deep');
});
