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
 * GeneratePhpIniStep guarantees docker/php.ini exists; what can still go wrong
 * is a lost Dockerfile COPY line, which ships green (nothing else imposes a
 * request-body limit) and only surfaces when a user's upload dies.
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
     * php_ini_scanned_files() reports what the runtime actually loaded, whatever
     * $PHP_INI_DIR resolves to in the base image.
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
