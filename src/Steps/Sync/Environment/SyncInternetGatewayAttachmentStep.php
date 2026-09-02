<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncInternetGatewayAttachmentStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $vpcId = $this->vpcIdOrNull();
        $attachment = $this->currentAttachment();

        if ($vpcId !== null
            && $attachment !== null
            && $attachment['VpcId'] === $vpcId
            && $attachment['State'] === 'available') {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        // Apply pass only: the VPC and gateway were created by the earlier env
        // steps, so both resolve strictly here.
        Aws::ec2()->attachInternetGateway([
            'InternetGatewayId' => (new InternetGateway())->arn(),
            'VpcId' => (new Vpc())->arn(),
        ]);

        return StepResult::CREATED;
    }

    /**
     * Null on a first-ever plan pass (SyncVpcStep hasn't run) — resolving eagerly
     * would abort the whole plan, so absence reports a pending WOULD_CREATE.
     */
    protected function vpcIdOrNull(): ?string
    {
        try {
            return (new Vpc())->arn();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Null when the gateway isn't provisioned yet (greenfield plan pass) or isn't
     * singly attached — both mean "not attached to our VPC".
     *
     * @return array<string, mixed>|null
     */
    protected function currentAttachment(): ?array
    {
        try {
            $attachments = Ec2::internetGateway((new InternetGateway())->name())['Attachments'];
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        return count($attachments) === 1 ? $attachments[0] : null;
    }
}
