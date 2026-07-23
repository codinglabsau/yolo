<?php

declare(strict_types=1);

use Codinglabs\Yolo\ProcessCommands;

describe('queue', function (): void {
    it('is the bare worker against the pinned SQS_QUEUE when no queue is given', function (): void {
        expect(ProcessCommands::queue())
            ->toBe('php artisan queue:work --tries=3 --max-time=3600');
    });

    it('appends an explicit --queue for a scoped or tiered worker', function (): void {
        expect(ProcessCommands::queue('yolo-testing-my-app-acme'))
            ->toBe('php artisan queue:work --tries=3 --max-time=3600 --queue=yolo-testing-my-app-acme');
    });

    it('passes a comma chain straight through so queue:work drains it strict-priority', function (): void {
        expect(ProcessCommands::queue('yolo-testing-my-app-acme-high,yolo-testing-my-app-acme-default'))
            ->toBe('php artisan queue:work --tries=3 --max-time=3600 --queue=yolo-testing-my-app-acme-high,yolo-testing-my-app-acme-default');
    });
});
