<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Panels\StatusPanel;
use Symfony\Component\Console\Output\BufferedOutput;

it('is the Overview tab on the o hotkey', function (): void {
    $panel = new StatusPanel(new BufferedOutput());

    expect($panel->title())->toBe('Overview')
        ->and($panel->hotkey())->toBe('o');
});

it('renders nothing when the app has no alarms', function (): void {
    expect(StatusPanel::alarmSummaryLines([], 80))->toBe([]);
});

it('summarises alarms as all clear when none are firing', function (): void {
    $body = implode("\n", StatusPanel::alarmSummaryLines([
        ['name' => 'yolo-prod-web-cpu', 'state' => 'OK', 'reason' => null],
        ['name' => 'yolo-prod-web-burst', 'state' => 'INSUFFICIENT_DATA', 'reason' => null],
    ], 80));

    expect($body)->toContain('Alarms')
        ->toContain('2 total')
        ->toContain('all clear');
});

it('lists only the firing alarms, with the count flagged', function (): void {
    $lines = StatusPanel::alarmSummaryLines([
        ['name' => 'yolo-prod-web-cpu', 'state' => 'OK', 'reason' => null],
        ['name' => 'yolo-prod-web-burst', 'state' => 'ALARM', 'reason' => 'too hot'],
    ], 120);

    $body = implode("\n", $lines);

    expect($body)->toContain('1 firing')
        ->toContain('web-burst')   // the firing alarm surfaces
        ->not->toContain('web-cpu'); // the OK one stays in `status:alarms`
});

it('truncates a firing alarm row so it never overruns the width', function (): void {
    $reason = str_repeat('Threshold Crossed: no datapoints were received. ', 8);

    $lines = StatusPanel::alarmSummaryLines([
        ['name' => 'yolo-production-codinglabs-web-worker-saturation', 'state' => 'ALARM', 'reason' => $reason],
    ], 80);

    foreach ($lines as $line) {
        $visible = preg_replace('/<[^>]+>/', '', $line);   // strip the Symfony colour tags
        expect(mb_strlen((string) $visible))->toBeLessThanOrEqual(80);
    }

    expect(implode("\n", $lines))->toContain('web-worker-saturation');
});
