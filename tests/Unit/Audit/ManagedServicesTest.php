<?php

use Codinglabs\Yolo\Audit\Audit;

/**
 * The orphan check trusts that SERVICE_BY_RESOURCE_GROUP lists exactly the
 * services YOLO has resource classes for — its keys are the `src/Resources/*`
 * directories. If a new service directory is added without a catalogue entry,
 * every resource of that service would be false-flagged as an orphan; if a
 * directory is dropped (as `DynamoDb` was) its entry must go too, which is
 * precisely what makes the leftover resources surface. This test keeps the
 * catalogue and the directories in lockstep so the invariant can't rot.
 */
it('catalogues exactly the src/Resources service directories', function () {
    $directories = collect(glob(dirname(__DIR__, 3) . '/src/Resources/*', GLOB_ONLYDIR))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values()
        ->all();

    $catalogued = collect(array_keys(Audit::SERVICE_BY_RESOURCE_GROUP))
        ->sort()
        ->values()
        ->all();

    expect($catalogued)->toBe($directories);
});

it('exposes the managed ARN services as the catalogue values', function () {
    expect(Audit::managedServices())
        ->toBe(array_values(Audit::SERVICE_BY_RESOURCE_GROUP))
        ->toContain('ecs', 'elasticloadbalancing', 's3', 'logs');
});
