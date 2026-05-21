<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncVpcStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::vpc();

            if (Manifest::has('aws.vpc')) {
                return StepResult::CUSTOM_MANAGED;
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::ec2()->createVpc([
                    'CidrBlock' => '10.1.0.0/16', // using 10.1 block instead of 10.0 to avoid conflicts with vapor
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'vpc',
                            ...Aws::tags([
                                'Name' => Helpers::keyedResourceName(exclusive: false),
                            ]),
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
