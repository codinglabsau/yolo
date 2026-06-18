<?php

declare(strict_types=1);

use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Sync\App\SyncTypesenseKeyStep;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

const EDGE_OFFER = "domain: example.com.au\nservices:\n  typesense:\n    version: \"29.0\"\n    cpu: 256\n    memory: 1024\n";

it('adds the search rate rule and carves the search host out of the general rate limit when active', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
    ], $captured);

    $wafCaptured = [];
    bindRoutedWafV2Client(['ListIPSets' => wafIpSetsResult()], $wafCaptured);

    $rules = collect((new WebAcl())->desiredRules())->keyBy('Name');

    expect($rules)->toHaveKey('yolo-search-rate-limit')
        ->and($rules['yolo-search-rate-limit']['Statement']['RateBasedStatement']['Limit'])->toBe(1000)
        ->and($rules['yolo-search-rate-limit']['Statement']['RateBasedStatement']['ScopeDownStatement']['ByteMatchStatement']['SearchString'])->toBe('search.example.com.au')
        ->and($rules['yolo-rate-limit']['Statement']['RateBasedStatement']['ScopeDownStatement']['NotStatement']['Statement']['ByteMatchStatement']['SearchString'])->toBe('search.example.com.au');
});

it('keeps the baseline rule set untouched while the environment is not running typesense', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "domain: example.com.au\nservices: {  }\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $wafCaptured = [];
    bindRoutedWafV2Client(['ListIPSets' => wafIpSetsResult()], $wafCaptured);

    $rules = collect((new WebAcl())->desiredRules())->keyBy('Name');

    expect($rules)->not->toHaveKey('yolo-search-rate-limit')
        ->and($rules['yolo-rate-limit']['Statement']['RateBasedStatement'])->not->toHaveKey('ScopeDownStatement');
});

it('typesense injects scout config, the private nodes and the public search host at build', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    // The env manifest's domain drives the public search host (search.{domain}).
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "domain: example.com.au\nservices:\n  typesense:\n    version: \"30.2\"\n"], $captured);

    expect(Service::TYPESENSE->definition()->buildValues())->toBe([
        'SCOUT_DRIVER' => 'typesense',
        'SCOUT_PREFIX' => 'yolo-testing-my-app_',
        'TYPESENSE_HOST' => 'typesense-0.testing.internal',
        'TYPESENSE_PORT' => '8108',
        'TYPESENSE_PROTOCOL' => 'http',
        'TYPESENSE_NODES' => 'typesense-0.testing.internal:8108:http,typesense-1.testing.internal:8108:http,typesense-2.testing.internal:8108:http',
        'TYPESENSE_SEARCH_HOST' => 'search.example.com.au',
        'TYPESENSE_SEARCH_PORT' => '443',
        'TYPESENSE_SEARCH_PROTOCOL' => 'https',
    ]);
});

it('hard-fails the build when a typesense app has no env domain — no silent dead search box', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services:\n  typesense:\n    version: \"30.2\"\n"], $captured);

    expect(fn (): array => Service::TYPESENSE->definition()->buildValues())
        ->toThrow(IntegrityCheckException::class, 'declare `domain`');
});

it('leaves an already-minted app alone', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    // The idempotency marker is the app's env-side `.env` (env/.env.my-app) in
    // the env config bucket — a present TYPESENSE_API_KEY means the pair is
    // already minted (both keys are written together).
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=already-minted\n"]),
    ], $captured);

    expect((new SyncTypesenseKeyStep('testing'))([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('reads its own env-side file as the idempotency marker, never the env-shared admin key', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    // The env-shared `.env` carries the admin key; the app's env-side
    // env/.env.my-app already carries this app's scoped key. The step keys
    // idempotency off its OWN file, so it's a no-op (no re-mint, no write).
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
        'appEnvSide' => ['my-app' => "TYPESENSE_API_KEY=scoped-key\n"],
    ], $captured);

    expect((new SyncTypesenseKeyStep('testing'))([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('plans the mint without any HTTP call, and mints + persists on apply', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
    ], $captured);

    $guzzle = new GuzzleMockHandler([
        new Response(201, [], (string) json_encode(['value' => 'server-key'])),
        new Response(201, [], (string) json_encode(['value' => 'search-key'])),
    ]);

    $planned = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->not->toBeEmpty();
    expect($guzzle->count())->toBe(2); // nothing consumed on the plan — both keys still queued

    $step = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($step([]))->toBe(StepResult::CREATED);
    expect($guzzle->count())->toBe(0); // apply mints both the server-side and the search key

    $put = collect($captured)->firstWhere('name', 'PutObject');
    expect($put['args']['Key'])->toBe('env/.env.my-app')
        ->and((string) $put['args']['Body'])->toContain('TYPESENSE_API_KEY=server-key')
        ->and((string) $put['args']['Body'])->toContain('TYPESENSE_SEARCH_KEY=search-key');
});

it('skips the mint with instructions while the cluster is not provisioned yet', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    // No env manifest, no shared .env — adminKey/searchHost unresolvable.
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    $step = new SyncTypesenseKeyStep('testing');

    // The reason is recorded, not printed — the runner replays buffered warnings
    // after the results table so they never collide with the live progress bar.
    expect($step([]))->toBe(StepResult::SKIPPED);
    expect($step->recordedWarnings())->toHaveCount(1)
        ->and($step->recordedWarnings()[0])->toContain('not provisioned yet');
});
