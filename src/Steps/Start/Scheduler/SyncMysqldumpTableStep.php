<?php

namespace Codinglabs\Yolo\Steps\Start\Scheduler;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ResolvesDatabases;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;

class SyncMysqldumpTableStep implements RunsOnAwsScheduler
{
    use ResolvesDatabases;

    public function __invoke(array $options): StepResult
    {
        $dir = sprintf('/home/ubuntu/yolo/%s', Helpers::keyedResourceName());
        $file = sprintf('%s/mysqldump-table.sh', $dir);

        if (! Manifest::get('mysqldump')) {
            if (file_exists($file)) {
                unlink($file);
            }

            return StepResult::SKIPPED;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
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
                subject: file_get_contents(Paths::stubs('mysqldump-table.sh.stub'))
            )
        );

        chmod($file, 0755);

        return StepResult::SYNCED;
    }
}
