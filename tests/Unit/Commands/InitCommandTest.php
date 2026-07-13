<?php

use Laravel\Prompts\Key;
use Codinglabs\Yolo\Paths;
use Laravel\Prompts\Prompt;
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

function scaffoldEnv(string $environment = 'testing'): void
{
    (function () use ($environment): void {
        $this->environment = $environment;
        $this->initialiseEnv();
    })->call(new InitCommand());
}

it('builds the starter env from .env.example corrected for the environment', function (): void {
    writeManifest(['account-id' => '111111111111', 'domain' => 'example.com']);
    file_put_contents(Paths::base('.env.example'), implode("\n", [
        'APP_NAME=Laravel',
        'APP_ENV=local',
        'APP_KEY=',
        'APP_DEBUG=true',
        'APP_URL=http://localhost',
        'MAIL_MAILER=log',
    ]) . "\n");

    Prompt::fake([Key::ENTER]);
    scaffoldEnv();

    $contents = file_get_contents(Paths::base('.env.testing'));

    expect($contents)
        ->toContain("APP_NAME=Laravel\n")   // app-specific keys survive
        ->toContain("MAIL_MAILER=log\n")
        ->toContain("APP_ENV=testing\n")
        ->toContain("APP_DEBUG=false\n")
        ->toContain("APP_URL=https://example.com\n")
        ->and($contents)->toMatch('/^APP_KEY=base64:.{44}$/m');
});

it('strips AWS_* and build-injected keys the platform owns', function (): void {
    writeManifest(['account-id' => '111111111111']);
    file_put_contents(Paths::base('.env.example'), implode("\n", [
        'APP_NAME=Laravel',
        '',
        // The whole stock AWS block, plus the keys the build enforces or
        // injects from the manifest — LOG_CHANNEL=stack would otherwise
        // hard-fail the first build against the enforced stderr.
        'AWS_ACCESS_KEY_ID=',
        'AWS_SECRET_ACCESS_KEY=',
        'AWS_DEFAULT_REGION=us-east-1',
        'AWS_BUCKET=',
        'AWS_USE_PATH_STYLE_ENDPOINT=false',
        '',
        'LOG_CHANNEL=stack',
        'QUEUE_CONNECTION=database',
        'CACHE_STORE=database',
        'SESSION_DRIVER=database',
        'REDIS_HOST=127.0.0.1',
        'REDIS_PORT=6379',
        'FILESYSTEM_DISK=local',
        '',
        'MAIL_MAILER=log',
    ]) . "\n");

    Prompt::fake([Key::ENTER]);
    scaffoldEnv();

    $contents = file_get_contents(Paths::base('.env.testing'));

    expect($contents)
        ->toContain("APP_NAME=Laravel\n")
        ->toContain("MAIL_MAILER=log\n")
        ->not->toContain('AWS_')
        ->not->toContain('LOG_CHANNEL')
        ->not->toContain('QUEUE_CONNECTION')
        ->not->toContain('CACHE_STORE')
        ->not->toContain('SESSION_DRIVER')
        ->not->toContain('REDIS_')
        ->not->toContain('FILESYSTEM_DISK')
        ->and($contents)->not->toMatch('/\n{3,}/');    // stripped blocks leave no gaps
});

it('appends environment keys the example does not declare', function (): void {
    writeManifest(['account-id' => '111111111111']);
    file_put_contents(Paths::base('.env.example'), "MAIL_MAILER=log\n");

    Prompt::fake([Key::ENTER]);
    scaffoldEnv();

    expect(file_get_contents(Paths::base('.env.testing')))
        ->toContain("MAIL_MAILER=log\n")
        ->toContain("APP_ENV=testing\n")
        ->toContain("APP_DEBUG=false\n")
        ->not->toContain('APP_URL');    // no domain in the manifest, no URL guess
});

it('scaffolds a minimal starter env when the app has no .env.example', function (): void {
    writeManifest(['account-id' => '111111111111']);

    Prompt::fake([Key::ENTER]);
    scaffoldEnv();

    $contents = file_get_contents(Paths::base('.env.testing'));

    expect($contents)
        ->toContain("APP_ENV=testing\n")
        ->toContain("APP_DEBUG=false\n")
        ->and($contents)->toMatch('/^APP_KEY=base64:.{44}$/m');
});

it('never overwrites an existing env file', function (): void {
    file_put_contents(Paths::base('.env.testing'), "APP_ENV=testing\nAPP_KEY=base64:existing\n");

    Prompt::fake();
    scaffoldEnv();

    expect(file_get_contents(Paths::base('.env.testing')))->toContain('APP_KEY=base64:existing');
});

it('skips the starter env when declined', function (): void {
    writeManifest(['account-id' => '111111111111']);

    Prompt::fake(['n', Key::ENTER]);
    scaffoldEnv();

    expect(file_exists(Paths::base('.env.testing')))->toBeFalse();
});

afterEach(function (): void {
    foreach (['.env.testing', '.env.example'] as $file) {
        if (file_exists(Paths::base($file))) {
            unlink(Paths::base($file));
        }
    }
});
