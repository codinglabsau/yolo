<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared public route table for the environment. The default 0.0.0.0/0 route
 * and the subnet associations are separate relationship actions.
 */
class RouteTable implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Delete the route table by id. The subnet associations go when the subnets
     * themselves are deleted (upstream teardown order) and the default 0.0.0.0/0
     * route goes with the table, so a plain delete succeeds; AWS would otherwise
     * refuse with DependencyViolation while a subnet is still associated. A
     * concurrent removal (InvalidRouteTableID.NotFound) is tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::ec2()->deleteRouteTable(['RouteTableId' => $this->arn()]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidRouteTableID.NotFound') {
                return;
            }

            throw $e;
        }
    }
}
