<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCodeDeployApplicationStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        try {
            $application = AwsResources::application();

            if (! Arr::get($options, 'dry-run')) {
                // AWS allows updates to the application name only,
                // so we'll eager merge tags when syncing
                Aws::codeDeploy()->tagResource([
                    'ResourceArn' => static::arnForApplication($application),
                    ...Aws::tags(),
                ]);
            }

            return StepResult::IN_SYNC;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createApplication([
                    'applicationName' => static::applicationName(),
                    ...Aws::tags([
                        'Name' => static::applicationName(),
                    ], wrap: 'tags'),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
