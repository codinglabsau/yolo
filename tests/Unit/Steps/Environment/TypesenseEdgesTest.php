<?php

declare(strict_types=1);

use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
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
        new Response(200, [], (string) json_encode(['ok' => true])), // GET /health — endpoint up, mint proceeds
        new Response(201, [], (string) json_encode(['value' => 'server-key'])),
        new Response(201, [], (string) json_encode(['value' => 'search-key'])),
    ]);

    $planned = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->not->toBeEmpty();
    expect($guzzle->count())->toBe(3); // nothing consumed on the plan — health probe + both keys still queued

    $step = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($step([]))->toBe(StepResult::CREATED);
    expect($guzzle->count())->toBe(0); // apply probes /health, then mints the server-side + search keys

    $put = collect($captured)->firstWhere('name', 'PutObject');
    expect($put['args']['Key'])->toBe('env/.env.my-app')
        ->and((string) $put['args']['Body'])->toContain('TYPESENSE_API_KEY=server-key')
        ->and((string) $put['args']['Body'])->toContain('TYPESENSE_SEARCH_KEY=search-key');
});

it('verifies stored keys against the cluster and stays SYNCED when they are honoured', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
        'appEnvSide' => ['my-app' => "TYPESENSE_API_KEY=stored-server\nTYPESENSE_SEARCH_KEY=stored-search\n"],
    ], $captured);

    // 404 = collection not found — auth passed, so the key pair is honoured.
    $guzzle = new GuzzleMockHandler([
        new Response(404, [], (string) json_encode(['message' => 'Not Found'])),
    ]);

    $step = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect($guzzle->count())->toBe(0); // exactly the one probe, no re-mint
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('re-creates a dead key pair with the SAME stored values — recovery without a rebuild', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
        'appEnvSide' => ['my-app' => "TYPESENSE_API_KEY=stored-server\nTYPESENSE_SEARCH_KEY=stored-search\n"],
    ], $captured);

    // A replaced (empty) cluster answers 401: stored keys are cluster data and
    // died with it, while the app keeps serving the baked values.
    $history = [];
    $guzzle = new GuzzleMockHandler([
        new Response(401, [], (string) json_encode(['message' => 'Forbidden'])), // plan probe
        new Response(401, [], (string) json_encode(['message' => 'Forbidden'])), // apply probe
        new Response(201, [], (string) json_encode(['value' => 'stored-server'])),
        new Response(201, [], (string) json_encode(['value' => 'stored-search'])),
    ]);
    $stack = HandlerStack::create($guzzle);
    $stack->push(Middleware::history($history));

    $planned = new SyncTypesenseKeyStep('testing', new Client(['handler' => $stack]));
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($planned->changes())->toHaveCount(2);

    $step = new SyncTypesenseKeyStep('testing', new Client(['handler' => $stack]));
    expect($step([]))->toBe(StepResult::SYNCED);

    // Both re-mints carried the stored values explicitly — deterministic, so
    // every existing build's baked keys work again the moment this applies.
    $mints = array_values(array_filter($history, fn (array $call): bool => $call['request']->getMethod() === 'POST'));
    expect($mints)->toHaveCount(2)
        ->and(json_decode((string) $mints[0]['request']->getBody(), true)['value'])->toBe('stored-server')
        ->and(json_decode((string) $mints[1]['request']->getBody(), true)['value'])->toBe('stored-search');

    // The values never changed, so the env-side file is left alone.
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('fails open with a warning when the stored keys cannot be verified', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => EDGE_OFFER,
        'sharedEnv' => "TYPESENSE_API_KEY=admin-key\n",
        'appEnvSide' => ['my-app' => "TYPESENSE_API_KEY=stored-server\nTYPESENSE_SEARCH_KEY=stored-search\n"],
    ], $captured);

    // An ALB with no healthy targets answers 5xx — that says nothing about the
    // key, and a down cluster must not wedge every sync.
    $guzzle = new GuzzleMockHandler([
        new Response(503, [], 'Service Unavailable'),
    ]);

    $step = new SyncTypesenseKeyStep('testing', new Client(['handler' => HandlerStack::create($guzzle)]));

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->recordedWarnings())->toHaveCount(1)
        ->and($step->recordedWarnings()[0])->toContain('Could not verify');
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
