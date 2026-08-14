<?php

declare(strict_types=1);

use Codinglabs\Yolo\ProcessCommands;

describe('web', function (): void {
    function manifestWithWeb(array $web): void
    {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => $web],
        ]);
    }

    it('runs Octane with the pinned worker pool by default', function (): void {
        manifestWithWeb(['autoscaling' => false]);

        expect(ProcessCommands::web())
            ->toBe('php artisan octane:start --host=0.0.0.0 --port=8000 --workers=8');
    });

    it('runs classic mode against the generated Caddyfile, never php-server', function (): void {
        // php-server takes no thread flag and reads no Caddyfile, so its pool is fixed
        // at 2x the microVM's visible CPUs with no way to override it. `run --config`
        // is the only launch form that can carry num_threads / max_threads.
        manifestWithWeb(['octane' => false]);

        expect(ProcessCommands::web())
            ->toBe('frankenphp run --config /app/docker/Caddyfile')
            ->not->toContain('php-server');
    });

    it('runs the same Caddyfile path both modes generate into', function (): void {
        manifestWithWeb(['octane' => false]);

        expect(ProcessCommands::web())->toContain(ProcessCommands::CADDYFILE);
    });
});

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
