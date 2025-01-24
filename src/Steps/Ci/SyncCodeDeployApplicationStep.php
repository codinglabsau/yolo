<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
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
                // AWS returns the application name only, so we'll eager update tags when syncing
                Aws::codeDeploy()->tagResource([
                    'ResourceArn' => sprintf(
                        'arn:aws:codedeploy:%s:%s:application:%s',
                        Manifest::get('aws.region'),
                        Aws::accountId(),
                        $application
                    ),
                    ...Aws::tags(),
                ]);
            }

            return StepResult::SYNCED;
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
