<?php

namespace Codinglabs\Yolo\Resources\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared public route table for the environment. The default 0.0.0.0/0 route
 * and the subnet associations are separate relationship actions.
 */
class RouteTable implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.route-table', $this->keyedName());
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ec2::routeTable($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::routeTable($this->name())['RouteTableId'];
    }

    public function create(): void
    {
        Aws::ec2()->createRouteTable([
            'VpcId' => (new Vpc())->arn(),
            'TagSpecifications' => [
                ['ResourceType' => 'route-table', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEc2Tags($this->arn(), $this->tags());
    }
}
