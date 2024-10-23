<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureVpcExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        $vpcName = Helpers::keyedResourceName(exclusive: false);

        try {
            if (AwsResources::vpc()) {
                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException $e) {
            Aws::ec2()->createVpc([
                'CidrBlock' => '10.1.0.0/16', // using 10.1 block instead of 10.0 to avoid conflicts with vapor
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'vpc',
                        'Tags' => [
                            [
                                'Key' => 'Name',
                                'Value' => $vpcName,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        return StepResult::CREATED;
    }
}
