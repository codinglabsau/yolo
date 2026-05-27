<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;

class SyncInternetGatewayAttachmentStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $vpcId = (new Vpc())->arn();
        $internetGateway = Ec2::internetGateway((new InternetGateway())->name());

        if (count($internetGateway['Attachments']) === 1
            && $internetGateway['Attachments'][0]['VpcId'] === $vpcId
            && $internetGateway['Attachments'][0]['State'] === 'available') {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        Aws::ec2()->attachInternetGateway([
            'InternetGatewayId' => $internetGateway['InternetGatewayId'],
            'VpcId' => $vpcId,
        ]);

        return StepResult::CREATED;
    }
}
