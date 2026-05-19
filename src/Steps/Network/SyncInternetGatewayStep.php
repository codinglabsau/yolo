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

class SyncInternetGatewayStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::internetGateway();

            if (Manifest::has('aws.internet-gateway')) {
                return StepResult::CUSTOM_MANAGED;
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::ec2()->createInternetGateway([
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'internet-gateway',
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
