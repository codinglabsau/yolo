<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;

class SyncMysqlBackupStep implements RunsOnAwsScheduler
{
    public function __invoke(array $options): StepResult
    {
        if (! env('DB_REPLICA_HOST')) {
            return StepResult::SKIPPED;
        }

        $file = "/home/ubuntu/mysqlbackup.sh";

        file_put_contents(
            $file,
            str_replace(
                search: [
                    '{DB_HOST}',
                    '{DB_USERNAME}',
                    '{DB_PASSWORD}',
                    '{AWS_BUCKET}',
                ],
                replace: [
                    env('DB_REPLICA_HOST'),
                    env('DB_USERNAME'),
                    env('DB_PASSWORD'),
                    Paths::s3ArtefactsBucket(),
                ],
                subject: file_get_contents(Paths::stubs('mysqlbackup.sh.stub'))
            )
        );

        chown($file, 'ubuntu');

        return StepResult::SYNCED;
    }
}
