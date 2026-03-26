<?php

namespace Codinglabs\Yolo\Steps\Start\Scheduler;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
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

        $dir = '/home/ubuntu/' . Helpers::keyedResourceName();

        @mkdir($dir, 0755, true);

        $file = $dir . '/mysqlbackup.sh';

        file_put_contents(
            $file,
            str_replace(
                search: [
                    '{APP_DIR}',
                    '{DB_HOST}',
                    '{DB_USERNAME}',
                    '{DB_PASSWORD}',
                    '{AWS_BUCKET}',
                    '{DATABASES}',
                ],
                replace: [
                    $dir,
                    env('DB_REPLICA_HOST', env('DB_HOST')),
                    env('DB_USERNAME'),
                    env('DB_PASSWORD'),
                    Paths::s3ArtefactsBucket(),
                    implode(' ', $this->databases()),
                ],
                subject: file_get_contents(Paths::stubs('mysqlbackup.sh.stub'))
            )
        );

        // own cron entry for the backup script
        file_put_contents(
            sprintf('/etc/cron.d/%s', Helpers::keyedResourceName('mysqlbackup')),
            str_replace('{SCRIPT_PATH}', $file, file_get_contents(Paths::stubs('cron/mysqlbackup.stub')))
        );

        return StepResult::SYNCED;
    }
}
