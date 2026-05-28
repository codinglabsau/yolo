<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
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
        return Manifest::get('aws.internet-gateway', $this->keyedName());
    }

    public function scope(): Scope
    {
        return Scope::Env;
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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }
}
