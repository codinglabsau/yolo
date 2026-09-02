<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Enums\PrivateSubnets;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The database tier, one per AZ: no public IPs and no internet route, so nothing in
 * them is ever reachable from outside the VPC.
 */
class PrivateSubnet implements Deletable, Resource
{
    use ResolvesTags;

    /** The public tier owns 10.N.0-2; starting at .10 leaves it room to grow. */
    protected const int CIDR_OFFSET = 10;

    public function __construct(protected int $index) {}

    /**
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return collect(array_keys(PrivateSubnets::cases()))
            ->map(fn (int $index): string => (new self($index))->arn())
            ->all();
    }

    public function name(): string
    {
        return $this->keyedName(PrivateSubnets::cases()[$this->index]->value);
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

        Aws::ec2()->createSubnet([
            'AvailabilityZone' => $availabilityZones[$this->index]['ZoneName'],
            'CidrBlock' => $this->carveFrom($vpc['CidrBlock']),
            'VpcId' => $vpc['VpcId'],
            'TagSpecifications' => [
                ['ResourceType' => 'subnet', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    /**
     * Best-effort at plan time, re-resolved at create. On a greenfield plan pass the
     * VPC doesn't exist yet, so the carve falls back to the /16 the VPC sync will
     * claim rather than throwing.
     */
    public function availableCidrBlock(): string
    {
        try {
            return $this->carveFrom(Ec2::vpc((new Vpc())->name())['CidrBlock']);
        } catch (ResourceDoesNotExistException) {
            return $this->carveFrom((new Vpc())->availableCidrBlock());
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * The database was never YOLO's to delete and blocks the whole network reclaim
     * while it lives, so by the time this runs the subnet is empty.
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

    /** YOLO owns the network, so every VPC holds a `10.N.0.0/16` and the carve is deterministic. */
    protected function carveFrom(string $vpcCidrBlock): string
    {
        $block = Str::before($vpcCidrBlock, '.0.0/16');

        return "{$block}." . (self::CIDR_OFFSET + $this->index) . '.0/24';
    }
}
