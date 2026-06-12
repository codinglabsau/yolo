<?php

declare(strict_types=1);

use Aws\Result;
use GuzzleHttp\Client;
use Laravel\Prompts\Prompt;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
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

it('keeps the baseline rule set untouched while typesense is not offered and claimed', function (): void {
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

it('typesense injects the scout driver, prefix and private node addresses at build', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    expect(Service::TYPESENSE->definition()->buildValues())->toBe([
        'SCOUT_DRIVER' => 'typesense',
        'SCOUT_PREFIX' => 'yolo-testing-my-app_',
        'TYPESENSE_HOST' => 'typesense-0.testing.internal',
        'TYPESENSE_PORT' => '8108',
        'TYPESENSE_PROTOCOL' => 'http',
        'TYPESENSE_NODES' => 'typesense-0.testing.internal:8108:http,typesense-1.testing.internal:8108:http,typesense-2.testing.internal:8108:http',
    ]);
});

it('leaves an already-minted app key alone', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=already-minted\n"]),
    ], $captured);

    expect((new SyncTypesenseKeyStep())([]))->toBe(StepResult::SYNCED);
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
        new Response(201, [], (string) json_encode(['value' => 'scoped-key'])),
    ]);

    $planned = new SyncTypesenseKeyStep(new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->not->toBeEmpty();
    expect($guzzle->count())->toBe(1); // nothing consumed on the plan

    $step = new SyncTypesenseKeyStep(new Client(['handler' => HandlerStack::create($guzzle)]));
    expect($step([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutObject');
    expect($put['args']['Key'])->toBe('.env.testing')
        ->and((string) $put['args']['Body'])->toContain('TYPESENSE_API_KEY=scoped-key');
});

it('skips the mint with instructions while the cluster is not provisioned yet', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['typesense'],
    ]);

    // No env manifest, no shared .env — adminKey/searchHost unresolvable.
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    Prompt::fake();

    expect((new SyncTypesenseKeyStep())([]))->toBe(StepResult::SKIPPED);
    expect(Prompt::content())->toContain('not provisioned yet');
});
