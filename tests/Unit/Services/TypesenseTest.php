<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\Service;
use Symfony\Component\Process\Process;
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

it('offers known stable versions newest-first and the valid node counts', function (): void {
    $options = Service::TYPESENSE->definition()->offerOptions();

    expect($options['version'])->toBe(Typesense::VERSIONS)
        ->and($options['version'][0])->toBe('30.2')   // newest is the default selection
        ->and($options['nodes'])->toBe(['3', '5']);   // mirrors NODE_COUNTS

    foreach ($options['version'] as $version) {
        expect($version)->toMatch('/^\d+\.\d+$/');     // stable tags only — never an rc/alpha
    }
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

it('content-tags the image by version + server-config fingerprint, unresolvable until both exist', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => [
            new Result(['Body' => "services:\n  typesense:\n    version: \"29.0\"\n    cpu: 256\n    memory: 1024\n"]), // env manifest
            new Result(['Body' => "TYPESENSE_API_KEY=abc123\n"]), // env-shared .env
        ],
    ], $captured);

    $tag = Typesense::imageTag();

    expect($tag)->toStartWith('29.0-')
        ->and($tag)->toBe('29.0-' . substr(hash('sha256', Typesense::serverConfig() . '|' . implode(',', Typesense::peers()) . '|' . Typesense::entrypointScript()), 0, 12));
});

it('bakes the admin key and any-origin CORS into the server config — so a CORS flip re-tags the image', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  typesense:\n    version: \"29.0\"\n",
        'sharedEnv' => "TYPESENSE_API_KEY=abc123\n",
    ], $captured);

    $config = Typesense::serverConfig();

    expect($config)->toContain('api-key = abc123')
        ->toContain('enable-cors = true')
        ->toContain(sprintf('api-port = %d', Typesense::API_PORT))
        ->toContain('nodes = /etc/typesense/nodes');

    // The image tag fingerprints the whole config, so the CORS line is part of it.
    expect(Typesense::imageTag())->not->toBeNull()
        ->and(Typesense::imageTag())->toContain(substr(hash('sha256', $config . '|' . implode(',', Typesense::peers()) . '|' . Typesense::entrypointScript()), 0, 12));
});

it('bakes a fail-closed peer-resolution entrypoint so Typesense never resolves DNS itself', function (): void {
    $script = Typesense::entrypointScript();

    // The wrapper owns resolution: it reads the baked hostname peer list and
    // writes the nodes file Typesense watches — IPs only, and (post-boot) only
    // on a round where every peer resolved, so a resolver wobble never reaches
    // raft. The refresh loop keys off resolve_peers' exit status, which is 0
    // only on a full set.
    expect($script)->toStartWith('#!/usr/bin/env bash')
        ->toContain('PEERS_FILE=/etc/typesense/peers')
        ->toContain('NODES_FILE=/etc/typesense/nodes')
        ->toContain('getent ahostsv4')
        ->toContain('if nodes=$(resolve_peers); then')
        ->toContain('exec /opt/typesense-server "$@"');

    // The server config still points Typesense at the runtime-written file.
    expect(Typesense::serverConfig())->toContain('nodes = /etc/typesense/nodes');
});

it('boots on self plus one peer, bounded — never deadlocked on a dead sibling', function (): void {
    $script = Typesense::entrypointScript();

    // A dead sibling has no DNS record, so a boot gate requiring the FULL
    // peer set can never open on a replacement node — no record, no boot; no
    // boot, no record; the whole cluster stays down. Booting needs only
    // enough to JOIN (this node itself + one peer), and past a bounded window
    // it proceeds with whatever resolves: fail-open on boot is safe because
    // the fail-closed refresh loop is what protects standing membership.
    expect($script)
        ->toContain('BOOT_TIMEOUT_SECONDS=120')
        ->toContain('bootable')
        ->toContain('self == 1 && others >= 1')
        ->toContain('boot gate timed out');
});

it('generates a syntactically valid entrypoint script', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'yolo-entrypoint-');
    file_put_contents($path, Typesense::entrypointScript());

    $process = new Process(['bash', '-n', $path]);
    $process->run();

    unlink($path);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('has no image tag while the version is undeclared', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services: {  }\n"], $captured);

    expect(Typesense::imageTag())->toBeNull();
});
