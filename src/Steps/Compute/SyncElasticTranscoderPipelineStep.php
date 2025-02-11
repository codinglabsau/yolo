<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncElasticTranscoderPipelineStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Manifest::get('aws.transcoder') === null) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::elasticTranscoderPipeline();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticTranscoder()->createPipeline([
                    'Name' => Helpers::keyedResourceName(),
                    'InputBucket' => Paths::s3AppBucket(),
                    'OutputBucket' => Paths::s3AppBucket(),
                    'Role' => 'arn:aws:iam::' . Aws::accountId() . ':role/Elastic_Transcoder_Default_Role',
                    // note: Elastic Transcoder does not appear to support tagging
//                    'TagSpecifications' => [
//                        [
//                            'ResourceType' => 'pipeline',
//                            ...Aws::tags([
//                                'Name' => Helpers::keyedResourceName(),
//                            ]),
//                        ],
//                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
