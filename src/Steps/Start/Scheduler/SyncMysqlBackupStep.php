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
        $dir = Paths::yoloDir();
        $logDir = Paths::logDir();
        $file = sprintf('%s/mysqlbackup.sh', $dir);
        $cron = sprintf('/etc/cron.d/%s', Helpers::keyedResourceName('mysqlbackup'));

        if (! Manifest::get('mysqldump')) {
            if (file_exists($file)) {
                unlink($file);
            }

            if (file_exists($cron)) {
                unlink($cron);
            }

            return StepResult::SKIPPED;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents(
            $file,
            str_replace(
                search: [
                    '{YOLO_DIR}',
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
            $cron,
            str_replace(
                ['{SCRIPT_PATH}', '{LOG_PATH}'],
                [$file, sprintf('%s/mysqlbackup.log', $logDir)],
                file_get_contents(Paths::stubs('cron/mysqlbackup.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
