<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Panels\AlarmsPanel;

it('is the Alarms tab on the a hotkey', function (): void {
    $panel = new AlarmsPanel();

    expect($panel->title())->toBe('Alarms')
        ->and($panel->hotkey())->toBe('a');
});
