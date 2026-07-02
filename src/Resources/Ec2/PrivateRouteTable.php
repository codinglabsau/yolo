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
 * Shared private route table for the environment — the private subnets'
 * routing. It only ever carries the implicit VPC-local route: no default route
 * is added, so nothing in the private tier can reach (or be reached from) the
 * internet. When `private-subnets` are adopted their routing stays with their
 * owner and this table isn't created at all.
 */
class PrivateRouteTable implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('private-route-table');
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
     * Delete the route table by id. The subnet associations go when the private
     * subnets themselves are deleted (upstream teardown order) and the local
     * route goes with the table, so a plain delete succeeds; AWS would
     * otherwise refuse with DependencyViolation while a subnet is still
     * associated. A concurrent removal (InvalidRouteTableID.NotFound) is
     * tolerated.
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
