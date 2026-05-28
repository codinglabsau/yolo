<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One of the three public subnets (one per availability zone), addressed by AZ
 * index 0-2. Each gets a /24 in the VPC's 10.1 block and auto-assigns public
 * IPs so Fargate tasks reach the internet without a NAT gateway. Point
 * `aws.public-subnets` at three existing subnet names to adopt instead.
 */
class PublicSubnet implements Resource
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
            ->map(fn (int $index) => (new self($index))->arn())
            ->all();
    }

    public function name(): string
    {
        if (Manifest::has('aws.public-subnets')) {
            return Manifest::get('aws.public-subnets')[$this->index];
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
        $availabilityZones = Ec2::availabilityZones(Manifest::get('aws.region'));

        Aws::ec2()->createSubnet([
            'AvailabilityZone' => $availabilityZones[$this->index]['ZoneName'],
            'CidrBlock' => "10.1.{$this->index}.0/24",
            'VpcId' => (new Vpc())->arn(),
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
}
