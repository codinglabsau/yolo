<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncInternetGatewayAttachmentStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $internetGatewayName = Helpers::keyedResourceName(exclusive: false);

        $vpc = AwsResources::vpc();
        $internetGateway = AwsResources::internetGateway();

        if (count($internetGateway['Attachments']) === 1
            && $internetGateway['Attachments'][0]['VpcId'] === $vpc['VpcId']
            && $internetGateway['Attachments'][0]['State'] === 'attached') {
            return StepResult::SYNCED;
        }

        if (! Arr::get($options, 'dry-run')) {
            Aws::ec2()->attachInternetGateway([
                'InternetGatewayId' => $internetGateway['InternetGatewayId'],
                'VpcId' => $vpc['VpcId'],
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'internet-gateway',
                        'Tags' => [
                            [
                                'Key' => 'Name',
                                'Value' => $internetGatewayName,
                            ],
                        ],
                    ],
                ],
            ]);

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
