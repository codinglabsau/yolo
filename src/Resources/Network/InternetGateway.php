<?php

namespace Codinglabs\Yolo\Resources\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared internet gateway for the environment. Attaching it to the VPC and
 * routing 0.0.0.0/0 through it are separate relationship actions
 * (SyncInternetGatewayAttachmentStep, SyncDefaultRouteStep).
 */
class InternetGateway implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.internet-gateway', Helpers::keyedResourceName(exclusive: false));
    }

    public function exists(): bool
    {
        try {
            Ec2::internetGateway($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::internetGateway($this->name())['InternetGatewayId'];
    }

    public function create(): void
    {
        Aws::ec2()->createInternetGateway([
            'TagSpecifications' => [
                ['ResourceType' => 'internet-gateway', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEc2Tags($this->arn(), $this->tags());
    }
}
