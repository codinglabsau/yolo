<?php

declare(strict_types=1);

use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Resources\Undeletable;

/**
 * The bring-your-own application data bucket holds user data and is never YOLO's
 * to delete. It is marked {@see Undeletable} and must never be {@see Deletable} —
 * the type-level half of the guarantee (the runtime half lives in
 * S3::deleteBucket() + teardownResource(), exercised in their own tests). A future
 * change that makes the data bucket deletable, or marks a deletable resource
 * Undeletable, fails CI here.
 */
it('marks the application data bucket Undeletable and never Deletable', function (): void {
    $reflection = new ReflectionClass(S3Bucket::class);

    expect($reflection->implementsInterface(Undeletable::class))->toBeTrue()
        ->and($reflection->implementsInterface(Deletable::class))->toBeFalse();
});

it('never lets a resource be both Deletable and Undeletable', function (): void {
    $resources = dirname(__DIR__, 2) . '/src/Resources';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([$resources, '/', '.php'], ['', '\\', ''], $file->getPathname());
        $class = 'Codinglabs\\Yolo\\Resources' . $relative;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->implementsInterface(Deletable::class) && $reflection->implementsInterface(Undeletable::class)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
