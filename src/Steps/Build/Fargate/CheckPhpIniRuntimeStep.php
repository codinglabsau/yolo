<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * The base image activates no php.ini, so a container with no app ini runs PHP's
 * compile defaults — 2M uploads, 8M POST bodies, opcache stat'ing an immutable
 * filesystem on every request — and nothing else in the stack imposes a
 * request-body limit, so the misconfig ships green and only surfaces when a
 * user's upload dies. GeneratePhpIniStep supplies docker/php.ini in every build
 * (YOLO's baseline, or the app's published copy), so the file always exists;
 * what can still go wrong is the Dockerfile — a lost or altered COPY line means
 * PHP never loads it. This probes the freshly-built image and hard-fails the
 * build — before the push — when PHP hasn't loaded the fragment. Probing php's
 * own scanned-file list (matching the other runtime checks' docker-run pattern)
 * sees every way the fragment can land, whatever $PHP_INI_DIR resolves to in
 * the base image.
 */
class CheckPhpIniRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageLoadsAppIni(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: PHP in the built image loads no app php.ini, so it would run '
            . 'compile defaults (2M uploads, 8M POST bodies). The build supplies docker/php.ini '
            . '(YOLO\'s baseline, or your published copy) — make sure your Dockerfile keeps the '
            . 'line `COPY docker/php.ini $PHP_INI_DIR/conf.d/yolo.ini`.'
        );
    }

    /**
     * The `docker run` probe. `--entrypoint php` bypasses YOLO's role-dispatch
     * entrypoint; php_ini_scanned_files() reports the fragments the runtime
     * actually loaded, so the check can't disagree with the PHP that serves.
     *
     * @return array<int, string>
     */
    public static function command(string $image): array
    {
        return [
            'docker', 'run', '--rm', '--entrypoint', 'php', $image,
            '-r', 'exit(str_contains((string) php_ini_scanned_files(), "yolo.ini") ? 0 : 1);',
        ];
    }

    protected function imageLoadsAppIni(string $image): bool
    {
        return (new Process(static::command($image)))->run() === 0;
    }
}
