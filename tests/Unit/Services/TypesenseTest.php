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
        ->and($definition->offerKeys())->toBe(['version', 'cpu', 'memory']);
});

it('requires version, cpu and memory in the offer', function (array $offer, string $needle): void {
    expect(fn () => Service::TYPESENSE->definition()->validateOffer($offer, 'yolo-environment-testing.yml'))
        ->toThrow(IntegrityCheckException::class, $needle);
})->with([
    'empty offer' => [[], 'version'],
    'missing cpu' => [['version' => '29.0', 'memory' => 1024], 'services.typesense.cpu'],
    'missing memory' => [['version' => '29.0', 'cpu' => 256], 'services.typesense.memory'],
    'non-int cpu' => [['version' => '29.0', 'cpu' => 'big', 'memory' => 1024], 'services.typesense.cpu'],
    'blank version' => [['version' => ' ', 'cpu' => 256, 'memory' => 1024], 'version'],
]);

it('accepts a complete offer', function (): void {
    Service::TYPESENSE->definition()->validateOffer(
        ['version' => '29.0', 'cpu' => 256, 'memory' => 1024],
        'yolo-environment-testing.yml',
    );

    expect(true)->toBeTrue();
});

it('declares three nodes with identical peer entries on stable DNS names', function (): void {
    expect(Typesense::peers())->toBe([
        'typesense-0.testing.internal:8107:8108',
        'typesense-1.testing.internal:8107:8108',
        'typesense-2.testing.internal:8107:8108',
    ]);
});

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
        ->and($tag)->toBe('29.0-' . substr(hash('sha256', 'abc123'), 0, 12));
});

it('has no image tag while the version is undeclared', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services: {  }\n"], $captured);

    expect(Typesense::imageTag())->toBeNull();
});
