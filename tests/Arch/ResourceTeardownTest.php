<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Resources\Undeletable;

/**
 * `yolo destroy` tears an environment down by deleting the live AWS resources it
 * owns, so every App-scoped resource must be able to delete itself — otherwise a
 * teardown would orphan it (and `yolo audit` would then flag it as unexpected).
 *
 * The deliberate exceptions are the resources YOLO must NEVER delete — the ones
 * marked {@see Undeletable}: the bring-your-own app data bucket ({@see S3Bucket}, holds
 * user data) and the hosted zone (domain-level infrastructure that outlives any app —
 * destroy:app withdraws its records but never deletes the zone). A new App-scoped
 * resource that forgets `delete()` fails this test until it implements {@see Deletable}
 * (or is deliberately marked {@see Undeletable}).
 *
 * Env-scoped resources aren't blanket-required to be deletable: `destroy:environment`
 * tears down the compute/edge tier (those resources do implement {@see Deletable}),
 * but the network shell it pins behind the database (VPC, subnets, the RDS security
 * group) is deliberately left standing, so requiring it here would be wrong. Account
 * scope is likewise out of scope. This test stays the floor for App scope only.
 */
it('every app-scoped resource is deletable, except the ones marked Undeletable', function (): void {
    $instantiate = function (ReflectionClass $reflection): object {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = array_map(function (ReflectionParameter $parameter): mixed {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

            return match (true) {
                $name === 'string' => 'example.com',
                $name === 'int' => 0,
                $name === 'float' => 0.0,
                $name === 'bool' => false,
                $name === 'array' => [],
                enum_exists($name) => $name::cases()[0],
                default => throw new RuntimeException(sprintf('Cannot default constructor parameter of type %s', $name)),
            };
        }, $constructor->getParameters());

        return $reflection->newInstanceArgs($arguments);
    };

    $src = dirname(__DIR__, 2) . '/src';

    $offenders = [];
    $examined = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src . '/Resources', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr((string) $file->getPathname(), strlen($src) + 1);
        $class = 'Codinglabs\\Yolo\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }
        if (! $reflection->implementsInterface(Resource::class)) {
            continue;
        }

        // scope() is a pure literal on every resource, so a throwaway instance is
        // safe to ask (no AWS, no manifest).
        if ($instantiate($reflection)->scope() !== Scope::App) {
            continue;
        }

        $examined++;

        // Resources marked Undeletable (the BYO data bucket, the hosted zone) must
        // survive a teardown by design — they're the deliberate exceptions. That
        // they must NOT be Deletable is asserted directly in tests/Arch/UndeletableTest.php.
        if ($reflection->implementsInterface(Undeletable::class)) {
            continue;
        }

        if (! $reflection->implementsInterface(Deletable::class)) {
            $offenders[] = $class . ' — App-scoped but does not implement Deletable';
        }
    }

    // Guard against a vacuous pass if path resolution ever breaks.
    expect($examined)->toBeGreaterThan(15);

    expect($offenders)->toBe([]);
});

/**
 * The hard counterpart to the invariant above, asserted directly (not through
 * the discovery loop, so it can never pass vacuously): the bring-your-own app
 * data bucket holds user data and MUST survive a teardown, so it must never be
 * made Deletable — no destroy step can ever reach it.
 */
it('never makes the BYO app data bucket deletable', function (): void {
    expect(class_implements(S3Bucket::class))->not->toContain(Deletable::class);
});
