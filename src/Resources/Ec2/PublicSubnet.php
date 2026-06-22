<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One of the three public subnets (one per availability zone), addressed by AZ
 * index 0-2. Each gets a /24 carved from whichever /16 the VPC was allocated
 * (its `10.N.{index}.0/24`) and auto-assigns public IPs so Fargate tasks reach
 * the internet without a NAT gateway. Point `public-subnets` at three existing
 * subnet names to adopt instead.
 */
class PublicSubnet implements Deletable, Resource
{
    use ResolvesTags;

    public function __construct(protected int $index) {}

    /**
     * Subnet IDs for all three public subnets, in AZ order — the shape the ALB
     * and the ECS service network configuration both expect.
     *
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return collect(array_keys(PublicSubnets::cases()))
            ->map(fn (int $index): string => (new self($index))->arn())
            ->all();
    }

    public function name(): string
    {
        if (Manifest::has('public-subnets')) {
            return Manifest::get('public-subnets')[$this->index];
        }

        return $this->keyedName(PublicSubnets::cases()[$this->index]->value);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ec2::subnet($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::subnet($this->name())['SubnetId'];
    }

    public function create(): void
    {
        $availabilityZones = Ec2::availabilityZones(Manifest::get('region'));
        $vpc = Ec2::vpc((new Vpc())->name());

        // Carve this subnet's /24 from the VPC's allocated /16 (10.N.0.0/16 →
        // 10.N.{index}.0/24) so subnets follow the auto-selected range.
        $block = Str::before($vpc['CidrBlock'], '.0.0/16');

        Aws::ec2()->createSubnet([
            'AvailabilityZone' => $availabilityZones[$this->index]['ZoneName'],
            'CidrBlock' => "{$block}.{$this->index}.0/24",
            'VpcId' => $vpc['VpcId'],
            'MapPublicIpOnLaunch' => true,
            'TagSpecifications' => [
                ['ResourceType' => 'subnet', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Delete this subnet by id. Assumes upstream teardown has already removed
     * everything in it — no Fargate ENIs remain and the route-table association
     * goes with the subnet — so a plain delete succeeds; AWS would otherwise
     * refuse with DependencyViolation. A concurrent removal
     * (InvalidSubnetID.NotFound) is tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::ec2()->deleteSubnet(['SubnetId' => $this->arn()]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidSubnetID.NotFound') {
                return;
            }

            throw $e;
        }
    }
}
