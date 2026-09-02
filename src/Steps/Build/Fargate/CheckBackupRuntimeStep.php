<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * Neither `mysqldump` nor `zstd` ships in a bare base image, and a missing one
 * fails silently: the deploy goes green and the backup errors unnoticed until
 * a restore is needed.
 */
class CheckBackupRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::backsUpDatabases()) {
            return StepResult::SKIPPED;
        }

        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageHasBackupTools(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: the built image is missing mysqldump and/or zstd, which the '
            . 'scheduled database backups run. Add them to your Dockerfile (e.g. `apk add '
            . '--no-cache mariadb-client zstd`), or remove `backups: true` from '
            . 'yolo.yml if this app has no database to back up.'
        );
    }

    /**
     * `--entrypoint sh` bypasses YOLO's role-dispatch entrypoint.
     *
     * @return array<int, string>
     */
    public static function command(string $image): array
    {
        return ['docker', 'run', '--rm', '--entrypoint', 'sh', $image, '-c', 'command -v mysqldump && command -v zstd'];
    }

    protected function imageHasBackupTools(string $image): bool
    {
        return (new Process(static::command($image)))->run() === 0;
    }
}
