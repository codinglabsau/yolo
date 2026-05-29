<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\DynamoDb\SessionsTable;

/**
 * Provisions the per-app DynamoDB sessions table only when the manifest selects
 * the `dynamodb` session driver. Other drivers (redis, database, cookie, file)
 * need no YOLO-provisioned infra here.
 */
class SyncDynamoDbSessionsTableStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::get('session.driver') !== 'dynamodb') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new SessionsTable(), $options);
    }
}
