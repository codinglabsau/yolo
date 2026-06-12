<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('provisions when the env manifest offers the service and a live app claims it', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Provision);
    expect(Lifecycle::liveAppsUsing(Service::IVS))->toBe(['my-app']);
});

it('tears down when the offer is present but no live app claims it', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown);
});

it('tears down when the service is not offered and every live app has published', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown);
});

it('a dead app cannot keep a service alive — only apps with running tasks count', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        // my-app's claim file lists ivs, but its cluster has no running tasks.
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => false],
    ], $captured);

    expect(Lifecycle::liveAppsUsing(Service::IVS))->toBe([]);
    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown);
});

it('retains (blocks teardown) while a running app has not published its services yet', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => [],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::unpublishedLiveApps())->toBe(['my-app']);
    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Retain);
});

it('does not provision on an offer alone — an unpublished live app is not a claim', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => [],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->not->toBe(ServiceState::Provision);
});

it('reads a greenfield environment (no config bucket) as an empty registry and tears nothing', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'bucket' => false,
        'clusters' => [],
    ], $captured);

    // Not offered, no claims, no live apps → Teardown state; with nothing
    // existing every step lands on SKIPPED. The point: no crash, no throw.
    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown);
    expect(Lifecycle::liveAppsUsing(Service::IVS))->toBe([]);
    expect(Lifecycle::unpublishedLiveApps())->toBe([]);
});

it('hard-fails when running apps still use a service the env manifest no longer declares', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(fn (): ServiceState => Lifecycle::state(Service::IVS))
        ->toThrow(IntegrityCheckException::class, 'no longer declares services.ivs');
});

it('hard-fails on an unreadable services file instead of reading it as unused', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => [
            new Result(['Body' => "services:\n  ivs: {}\n"]), // env manifest
            new Result(['Body' => "not-a-claim\n"]), // apps/broken.yml
        ],
        'ListObjectsV2' => new Result([
            'Contents' => [['Key' => 'apps/broken.yml']],
            'IsTruncated' => false,
        ]),
    ], $captured);

    // The broken app is live, so the registry is genuinely consulted — a
    // registry we can't read must never report "no claims".
    $ecsCaptured = [];
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-broken']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
    ], $ecsCaptured);

    expect(fn (): ServiceState => Lifecycle::state(Service::IVS))
        ->toThrow(IntegrityCheckException::class, 'apps/broken.yml');
});

it('memoises the registry per process so the plan and apply passes agree', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => true],
    ], $captured);

    Lifecycle::state(Service::IVS);
    $listCalls = count(array_filter($captured, fn (array $call): bool => $call['name'] === 'ListObjectsV2'));

    Lifecycle::state(Service::IVS);

    expect(count(array_filter($captured, fn (array $call): bool => $call['name'] === 'ListObjectsV2')))->toBe($listCalls);
});
