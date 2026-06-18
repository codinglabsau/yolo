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

it('provisions whenever the env manifest declares the service — no consumer needed', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []], // declared, but nothing consumes it
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Provision);
});

it('keeps a declared service provisioned even when its consumer is down', function (): void {
    // The footgun the redesign fixes: under the old consumption-gated model a
    // consumer being at zero running tasks at sync time would tear the cluster
    // (and its data) down. Declaration now drives provisioning, so it holds.
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => false], // consumer has no running tasks
    ], $captured);

    expect(Lifecycle::liveAppsUsing(Service::IVS))->toBe([]) // genuinely no live consumer
        ->and(Lifecycle::state(Service::IVS))->toBe(ServiceState::Provision); // still provisioned
});

it('tears down when the env manifest does not declare the service', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown);
});

it('reads a greenfield environment (no config bucket) as not declared → teardown', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'bucket' => false,
        'clusters' => [],
    ], $captured);

    // Not declared, nothing existing → Teardown, every step lands on SKIPPED.
    // The point: no crash, no throw.
    expect(Lifecycle::state(Service::IVS))->toBe(ServiceState::Teardown)
        ->and(Lifecycle::liveAppsUsing(Service::IVS))->toBe([])
        ->and(Lifecycle::unpublishedLiveApps())->toBe([]);
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
            new Result(['Body' => "services: {  }\n"]), // env manifest — does NOT declare ivs
            new Result(['Body' => "not-a-claim\n"]), // apps/broken.yml
        ],
        'ListObjectsV2' => new Result([
            'Contents' => [['Key' => 'apps/broken.yml']],
            'IsTruncated' => false,
        ]),
    ], $captured);

    // ivs isn't declared, so state() consults the registry to check nobody's
    // still using it — and a registry we can't read must never report "no use".
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
        'manifest' => "services: {  }\n", // not declared → state() consults the registry
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    Lifecycle::state(Service::IVS);
    $listCalls = count(array_filter($captured, fn (array $call): bool => $call['name'] === 'ListObjectsV2'));

    Lifecycle::state(Service::IVS);

    expect(count(array_filter($captured, fn (array $call): bool => $call['name'] === 'ListObjectsV2')))->toBe($listCalls);
});
