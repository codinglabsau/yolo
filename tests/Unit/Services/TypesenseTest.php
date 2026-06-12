<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is env-backed with no runtime IAM — consumption is env injection only', function (): void {
    $definition = Service::TYPESENSE->definition();

    expect($definition->envBacked())->toBeTrue()
        ->and($definition->taskRoleStatements())->toBe([])
        ->and($definition->offerKeys())->toBe(['version', 'nodes', 'cpu', 'memory']);
});

it('rejects a misshapen entry', function (array $offer, string $needle): void {
    expect(fn () => Service::TYPESENSE->definition()->validateOffer($offer, 'yolo-environment-testing.yml'))
        ->toThrow(IntegrityCheckException::class, $needle);
})->with([
    'no version' => [[], 'version'],
    'blank version' => [['version' => ' '], 'version'],
    'non-numeric cpu' => [['version' => '29.0', 'cpu' => 'big'], 'services.typesense.cpu'],
    'zero memory' => [['version' => '29.0', 'memory' => 0], 'services.typesense.memory'],
]);

it('follows the tasks.* conventions — version required, sizing optional in either numeric style', function (array $offer): void {
    Service::TYPESENSE->definition()->validateOffer($offer, 'yolo-environment-testing.yml');

    expect(true)->toBeTrue();
})->with([
    'version only (sizing defaults)' => [['version' => '29.0']],
    'bare numerics' => [['version' => '29.0', 'cpu' => 256, 'memory' => 1024]],
    'quoted numerics, tasks.web-style' => [['version' => '29.0', 'cpu' => '512', 'memory' => '2048']],
]);

it('defaults the per-node sizing to the seed shape', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services:\n  typesense:\n    version: \"29.0\"\n"], $captured);

    expect(Typesense::cpu())->toBe(256)
        ->and(Typesense::memory())->toBe(1024);
});

it('declares three nodes with identical peer entries on stable DNS names', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services: {  }\n"], $captured);

    expect(Typesense::peers())->toBe([
        'typesense-0.testing.internal:8107:8108',
        'typesense-1.testing.internal:8107:8108',
        'typesense-2.testing.internal:8107:8108',
    ])->and(Typesense::quorumFloor())->toBe(2);
});

it('runs five nodes when the manifest says so, with the write floor at three', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services:\n  typesense:\n    version: \"29.0\"\n    nodes: 5\n"], $captured);

    expect(Typesense::nodes())->toBe(5)
        ->and(count(Typesense::peers()))->toBe(5)
        ->and(Typesense::quorumFloor())->toBe(3);
});

it('rejects node counts other than 3 or 5, in plain terms', function (mixed $nodes): void {
    expect(fn () => Service::TYPESENSE->definition()->validateOffer(['version' => '29.0', 'nodes' => $nodes], 'yolo-environment-testing.yml'))
        ->toThrow(IntegrityCheckException::class, 'must be 3 or 5');
})->with([
    'one' => [1],
    'even' => [4],
    'seven' => [7],
    'word' => ['lots'],
]);

it('accepts a node count in either numeric style', function (mixed $nodes): void {
    Service::TYPESENSE->definition()->validateOffer(['version' => '29.0', 'nodes' => $nodes], 'yolo-environment-testing.yml');

    expect(true)->toBeTrue();
})->with([
    'bare' => [5],
    'quoted' => ['3'],
]);

it('reads the admin key from the env-shared .env and memoises it', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "SOME_OTHER=1\nTYPESENSE_API_KEY=abc123\n"]),
    ], $captured);

    expect(Typesense::adminKey())->toBe('abc123');

    Typesense::adminKey();

    expect(count(array_filter($captured, fn (array $call): bool => $call['name'] === 'GetObject')))->toBe(1);
});

it('reads a missing env-shared .env (or bucket) as no key yet', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    expect(Typesense::adminKey())->toBeNull();
});

it('content-tags the image by version + key fingerprint, unresolvable until both exist', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => [
            new Result(['Body' => "services:\n  typesense:\n    version: \"29.0\"\n    cpu: 256\n    memory: 1024\n"]), // env manifest
            new Result(['Body' => "TYPESENSE_API_KEY=abc123\n"]), // env-shared .env
        ],
    ], $captured);

    $tag = Typesense::imageTag();

    expect($tag)->toStartWith('29.0-')
        ->and($tag)->toBe('29.0-' . substr(hash('sha256', 'abc123|' . implode(',', Typesense::peers())), 0, 12));
});

it('has no image tag while the version is undeclared', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services: {  }\n"], $captured);

    expect(Typesense::imageTag())->toBeNull();
});
