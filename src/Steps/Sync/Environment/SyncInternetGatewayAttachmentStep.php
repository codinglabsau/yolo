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

        // Already attached to our VPC and available → nothing to do.
        if ($vpcId !== null
            && $attachment !== null
            && $attachment['VpcId'] === $vpcId
            && $attachment['State'] === 'available') {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        // Apply pass only: the VPC and internet gateway have been created by the
        // earlier env steps (SyncVpcStep → SyncInternetGatewayStep → here), so
        // both resolve. WOULD_CREATE keeps this step in the apply set (see the
        // plan→apply contract in RunsSteppedCommands::stepHasPendingWork).
        Aws::ec2()->attachInternetGateway([
            'InternetGatewayId' => (new InternetGateway())->arn(),
            'VpcId' => (new Vpc())->arn(),
        ]);

        return StepResult::CREATED;
    }

    /**
     * Our VPC's id, or null when it isn't provisioned yet. A first-ever sync's
     * plan pass runs before SyncVpcStep has created it; resolving the id eagerly
     * there would throw ResourceDoesNotExistException and abort the whole plan
     * (the two-pass contract), so absence is reported as a pending WOULD_CREATE.
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
     * The internet gateway's sole attachment, or null when the gateway isn't
     * provisioned yet (greenfield plan pass) or carries no/other-than-one
     * attachment — both mean "not attached to our VPC", so the step plans a
     * WOULD_CREATE rather than throwing.
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
