<?php

use Codinglabs\Yolo\Paths;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckSsrRuntimeStep;

/**
 * The guard runs non-interactively here, so the confirm() that fires on a missing
 * Node runtime returns its default (true) and the build proceeds — exactly the
 * behaviour of the deploy GHA. The warning is swallowed by the buffered output.
 */
beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());
});

afterEach(function () {
    is_file(Paths::base('Dockerfile')) && unlink(Paths::base('Dockerfile'));
});

it('passes without reading the Dockerfile when ssr is off', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    // No Dockerfile on disk — proof the step short-circuits before touching it.
    expect((new CheckSsrRuntimeStep('testing'))())->toBe(StepResult::SUCCESS);
});

it('passes when the Dockerfile installs a Node runtime', function (string $dockerfile) {
    file_put_contents(Paths::base('Dockerfile'), $dockerfile);

    expect((new CheckSsrRuntimeStep('testing'))())->toBe(StepResult::SUCCESS);
})->with([
    'apk nodejs' => ["FROM dunglas/frankenphp:1-php8.4-alpine\nRUN apk add --no-cache nodejs npm\n"],
    'apt nodejs' => ["FROM dunglas/frankenphp:1-php8.4\nRUN apt-get install -y nodejs\n"],
    'FROM node stage' => ["FROM node:22-alpine AS assets\nFROM dunglas/frankenphp:1-php8.4-alpine\n"],
    'COPY --from=node' => ["FROM dunglas/frankenphp:1-php8.4-alpine\nCOPY --from=node:22 /usr/local/bin/node /usr/local/bin/node\n"],
]);

it('proceeds (non-interactively) when no Node runtime is detected', function () {
    file_put_contents(Paths::base('Dockerfile'), "FROM dunglas/frankenphp:1-php8.4-alpine\nRUN apk add --no-cache git supervisor\n");

    // Warn-and-confirm: in a non-interactive build the confirm auto-approves, so
    // the build is never blocked by the heuristic.
    expect((new CheckSsrRuntimeStep('testing'))())->toBe(StepResult::SUCCESS);
});

it('proceeds when the Dockerfile is missing entirely', function () {
    // The missing-Dockerfile error belongs to the image build, not this guard.
    expect((new CheckSsrRuntimeStep('testing'))())->toBe(StepResult::SUCCESS);
});
