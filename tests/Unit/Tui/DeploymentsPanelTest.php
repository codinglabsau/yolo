<?php

use Carbon\Carbon;
use Codinglabs\Yolo\Tui\Panels\DeploymentsPanel;

it('lists deploy history newest-first with the current version marked', function (): void {
    $targets = [
        ['version' => '26.24.2.1130', 'pushedAt' => Carbon::now()->subHours(2)->getTimestamp()],
        ['version' => '26.24.1.0900', 'pushedAt' => Carbon::now()->subDay()->getTimestamp()],
    ];

    $body = implode("\n", DeploymentsPanel::historyLines($targets, '26.24.2.1130'));

    expect($body)->toContain('26.24.2.1130')
        ->toContain('26.24.1.0900')
        ->toContain('current')
        ->toContain('pushed');
});

it('shows an empty state when ECR has no versions', function (): void {
    expect(implode("\n", DeploymentsPanel::historyLines([], null)))->toContain('No previous versions');
});
