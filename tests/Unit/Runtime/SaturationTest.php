<?php

declare(strict_types=1);

use Codinglabs\Yolo\Runtime\Saturation;

it('parses busy/total worker saturation as a percentage', function (): void {
    expect(Saturation::parse("frankenphp_busy_workers 3\nfrankenphp_total_workers 4\n"))->toBe(75.0);
});

it('reads labelled gauges', function (): void {
    expect(Saturation::parse("frankenphp_busy_workers{worker=\"/app\"} 2\nfrankenphp_total_workers{worker=\"/app\"} 4\n"))
        ->toBe(50.0);
});

it('sums busy and total gauges across every worker entry', function (): void {
    expect(Saturation::parse(
        "frankenphp_busy_workers{worker=\"/a\"} 3\nfrankenphp_busy_workers{worker=\"/b\"} 3\n"
        . "frankenphp_total_workers{worker=\"/a\"} 4\nfrankenphp_total_workers{worker=\"/b\"} 4\n"
    ))->toBe(75.0);
});

it('reads zero saturation at rest', function (): void {
    expect(Saturation::parse("frankenphp_busy_workers 0\nfrankenphp_total_workers 4\n"))->toBe(0.0);
});

it('is null when the gauges are absent (metrics off / classic mode)', function (): void {
    expect(Saturation::parse("frankenphp_other 1\n"))->toBeNull();
});

it('drops an impossible busy>total reading rather than false-firing', function (): void {
    expect(Saturation::parse("frankenphp_busy_workers 15\nfrankenphp_total_workers 4\n"))->toBeNull();
});

it('drops a zero-total reading caught mid worker-reload', function (): void {
    expect(Saturation::parse("frankenphp_busy_workers 0\nfrankenphp_total_workers 0\n"))->toBeNull();
});
