<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Commands\InitCommand;
use Codinglabs\Yolo\Commands\SyncCommand;

function dockerignorePatterns(): array
{
    return collect(explode("\n", file_get_contents(Paths::stubs('.dockerignore.stub'))))
        ->map(fn ($line): string => trim((string) $line))
        ->reject(fn ($line): bool => $line === '' || str_starts_with((string) $line, '#'))
        ->values()
        ->all();
}

it('ships a .dockerignore stub that trims the obvious noise', function (): void {
    expect(dockerignorePatterns())->toContain('node_modules', '.git', 'tests', '.env.*');
});

it('never ignores what the image is built from', function (): void {
    // These live in the build context and the Dockerfile depends on them —
    // ignoring any would silently break the deploy.
    expect(dockerignorePatterns())->not->toContain('.env', 'vendor', 'public/build', 'docker', '.yolo-entrypoint.sh');
});

it('scaffolds web autoscaling on by default', function (): void {
    // Drive the literal stub (placeholders filled) through the real Manifest
    // reader — proves the scaffold parses to autoscaling-on, not just a string match.
    file_put_contents(Paths::manifest(), str_replace(
        ['{NAME}', '{ENVIRONMENT}', '{AWS_ACCOUNT_ID}', '{AWS_REGION}'],
        ['my-app', 'production', '111111111111', 'ap-southeast-2'],
        file_get_contents(Paths::stubs('yolo.yml.stub'))
    ));
    Helpers::app()->instance('environment', 'production');

    expect(Manifest::isAutoscaling())->toBeTrue();
})->after(fn (): bool => @unlink(Paths::manifest()));

it('scaffolds a manifest that satisfies the autoscaling-required integrity gate', function (): void {
    // The stub declares an explicit `autoscaling`, so the scaffold a fresh app gets
    // must pass `ensureManifestIntegrity` rather than tripping the new requirement.
    file_put_contents(Paths::manifest(), str_replace(
        ['{NAME}', '{ENVIRONMENT}', '{AWS_ACCOUNT_ID}', '{AWS_REGION}'],
        ['my-app', 'production', '111111111111', 'ap-southeast-2'],
        file_get_contents(Paths::stubs('yolo.yml.stub'))
    ));
    Helpers::app()->instance('environment', 'production');

    $command = new SyncCommand();
    $gate = new ReflectionMethod($command, 'ensureManifestIntegrity');

    expect($gate->invoke($command))->toBeTrue();
})->after(fn (): bool => @unlink(Paths::manifest()));

it('scaffolds a .dockerignore when none exists', function (): void {
    $path = Paths::base('.dockerignore');

    (function (): void {
        $this->initialiseDockerignore();
    })->call(new InitCommand());

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe(file_get_contents(Paths::stubs('.dockerignore.stub')));
})->after(fn (): bool => @unlink(Paths::base('.dockerignore')));

it('templates the environment block rather than hardcoding production', function (): void {
    $stub = file_get_contents(Paths::stubs('yolo.yml.stub'));

    expect($stub)->toContain('{ENVIRONMENT}:')
        ->and($stub)->not->toContain("\n  production:");
});

it('scaffolds the starter env file under the chosen environment', function (): void {
    (function (): void {
        $this->environment = 'staging';
        $this->initialiseEnv();
    })->call(new InitCommand());

    $path = Paths::base('.env.staging');

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe("APP_ENV=staging\nAPP_KEY=\nAPP_DEBUG=false\n");
})->after(fn (): bool => @unlink(Paths::base('.env.staging')));
