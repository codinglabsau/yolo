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
 * user's upload dies. This probes the freshly-built image for the published ini
 * (docker/php.ini, COPY'd to $PHP_INI_DIR/conf.d/yolo.ini by the scaffolded
 * Dockerfile) and hard-fails the build — before the push — when PHP hasn't
 * loaded it. Probing php's own scanned-file list (matching the other runtime
 * checks' docker-run pattern) sees every way the fragment can land, whatever
 * $PHP_INI_DIR resolves to in the base image.
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
            . 'compile defaults (2M uploads, 8M POST bodies). Publish the baseline — copy '
            . 'vendor/codinglabsau/yolo/stubs/php.ini.stub to docker/php.ini — and COPY it '
            . 'in your Dockerfile: `COPY docker/php.ini $PHP_INI_DIR/conf.d/yolo.ini`.'
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
