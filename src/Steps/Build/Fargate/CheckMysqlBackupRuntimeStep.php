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
 * Scheduled database backups shell out to `mysqldump` and `zstd` inside the
 * scheduler host's container, and neither ships in a bare base image. This
 * probes the freshly-built image for both binaries and hard-fails the build —
 * before the push — when either is missing. The failure it prevents is silent
 * in the worst way: the deploy goes green, the app serves, and the daily
 * backup errors unnoticed in the scheduler's logs until the day a restore is
 * needed. Skipped when the manifest opts out (`mysqldump: false` — e.g. a
 * non-MySQL app) or when cron runs nowhere to host the dump.
 */
class CheckMysqlBackupRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::backsUpMysql()) {
            return StepResult::SKIPPED;
        }

        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageHasBackupTools(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: the built image is missing mysqldump and/or zstd, which the '
            . 'scheduled database backups run. Add them to your Dockerfile (e.g. `apk add '
            . '--no-cache mariadb-client zstd`), or remove `mysqldump: true` from '
            . 'yolo.yml if this app has no MySQL database to back up.'
        );
    }

    /**
     * The `docker run` probe. `--entrypoint sh` bypasses YOLO's role-dispatch
     * entrypoint; `command -v` resolves against the same PATH the running
     * container sees, so a binary from the base image, a multi-stage COPY or
     * a script install all count.
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
