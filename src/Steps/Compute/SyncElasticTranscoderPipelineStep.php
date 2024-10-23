<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncElasticTranscoderPipelineStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::elasticTranscoderPipeline();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticTranscoder()->createPipeline([
                    'Name' => Helpers::keyedResourceName(),
                    'InputBucket' => Manifest::get('aws.bucket'),
                    'OutputBucket' => Manifest::get('aws.bucket'),
                    'Role' => 'arn:aws:iam::' . Manifest::get('aws.account-id') . ':role/Elastic_Transcoder_Default_Role',
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
