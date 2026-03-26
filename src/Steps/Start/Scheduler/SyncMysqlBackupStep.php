<?php

namespace Codinglabs\Yolo\Steps\Start\Scheduler;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ResolvesDatabases;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;

class SyncMysqlBackupStep implements RunsOnAwsScheduler
{
    use ResolvesDatabases;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('mysqldump')) {
            return StepResult::SKIPPED;
        }

        $file = '/home/ubuntu/mysqlbackup.sh';

        file_put_contents(
            $file,
            str_replace(
                search: [
                    '{DB_HOST}',
                    '{DB_USERNAME}',
                    '{DB_PASSWORD}',
                    '{AWS_BUCKET}',
                    '{DATABASES}',
                ],
                replace: [
                    env('DB_REPLICA_HOST', env('DB_HOST')),
                    env('DB_USERNAME'),
                    env('DB_PASSWORD'),
                    Paths::s3ArtefactsBucket(),
                    implode(' ', $this->databases()),
                ],
                subject: file_get_contents(Paths::stubs('mysqlbackup.sh.stub'))
            )
        );

        chown($file, 'ubuntu');

        return StepResult::SYNCED;
    }
}
