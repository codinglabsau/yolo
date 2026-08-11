<?php

declare(strict_types=1);

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\ExecuteBuildCommandStep;

/**
 * The build hook's env is an allowlist, not the whole file: the staged env
 * holds live credentials, and a hook must not be able to reach a real service
 * from the build host. These cases pin both halves — what crosses, and what
 * must not.
 *
 * The step runs a real child process, so the assertions come from a script
 * that dumps the env it was actually handed. Symfony merges that env into the
 * parent's, so the withholding cases assert on keys the staged file defines
 * and the surrounding shell does not.
 */
function runBuildHookDumpingEnv(string $stagedEnv): array
{
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }

    file_put_contents(Paths::build('.env.testing.tmp'), $stagedEnv);
    file_put_contents(Paths::build('dump-env.sh'), "#!/bin/sh\nenv > env.dump\n");
    chmod(Paths::build('dump-env.sh'), 0755);

    $result = (new ExecuteBuildCommandStep('testing', './dump-env.sh'))();

    expect($result)->toBe(StepResult::SUCCESS);

    // Split by hand rather than through Dotenv: Symfony merges the given env
    // into the parent's, so the dump also carries whatever the developer's (or
    // CI's) shell holds, which need not be dotenv-parseable.
    $dumped = [];

    foreach (explode("\n", (string) file_get_contents(Paths::build('env.dump'))) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $dumped[$key] = $value;
        }
    }

    foreach (['.env.testing.tmp', 'dump-env.sh', 'env.dump'] as $file) {
        unlink(Paths::build($file));
    }

    return $dumped;
}

it('passes APP_URL to build hooks so baked artefacts carry the real origin', function (): void {
    // Regression guard: a hook that bakes URLs into its output (a route
    // manifest, an SSR bundle, a sitemap) reads APP_URL. Without it Laravel
    // falls back to http://localhost and that origin ships inside the build.
    $env = runBuildHookDumpingEnv("APP_URL=https://app.example.com\n");

    expect($env)->toHaveKey('APP_URL')
        ->and($env['APP_URL'])->toBe('https://app.example.com');
});

it('passes the build-facing keys through and withholds everything else', function (): void {
    $env = runBuildHookDumpingEnv(implode("\n", [
        'APP_ENV=production',
        'APP_URL=https://app.example.com',
        'ASSET_URL=https://cdn.example.com/builds/1',
        'VITE_ASSET_URL=https://cdn.example.com/builds/1',
        'DB_PASSWORD=hunter2',
        'AWS_SECRET_ACCESS_KEY=shhh',
        'REDIS_HOST=cache.internal',
    ]) . "\n");

    expect($env)
        ->toHaveKeys(['APP_ENV', 'APP_URL', 'ASSET_URL', 'VITE_ASSET_URL'])
        ->not->toHaveKey('DB_PASSWORD')
        ->not->toHaveKey('AWS_SECRET_ACCESS_KEY')
        ->not->toHaveKey('REDIS_HOST');
});
