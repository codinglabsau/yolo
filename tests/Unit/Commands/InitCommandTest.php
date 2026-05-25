<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Commands\InitCommand;

function dockerignorePatterns(): array
{
    return collect(explode("\n", file_get_contents(Paths::stubs('.dockerignore.stub'))))
        ->map(fn ($line) => trim($line))
        ->reject(fn ($line) => $line === '' || str_starts_with($line, '#'))
        ->values()
        ->all();
}

it('ships a .dockerignore stub that trims the obvious noise', function () {
    expect(dockerignorePatterns())->toContain('node_modules', '.git', 'tests', '.env.*');
});

it('never ignores what the image is built from', function () {
    // These live in the build context and the Dockerfile depends on them —
    // ignoring any would silently break the deploy.
    expect(dockerignorePatterns())->not->toContain('.env', 'vendor', 'public/build', 'docker', '.yolo-entrypoint.sh');
});

it('scaffolds a .dockerignore when none exists', function () {
    $path = Paths::base('.dockerignore');

    (function () {
        $this->initialiseDockerignore();
    })->call(new InitCommand());

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe(file_get_contents(Paths::stubs('.dockerignore.stub')));
})->after(fn () => @unlink(Paths::base('.dockerignore')));
