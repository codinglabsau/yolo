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
 * One of the three private subnets (one per availability zone), addressed by AZ
 * index 0-2 — the database tier. No public IPs and no internet route: their
 * route table carries only the VPC-local route, so nothing in them is ever
 * reachable from outside the VPC. Each gets a /24 carved deterministically from
 * the VPC's /16 (`10.N.{10 + index}.0/24` — offset past the public tier's
 * 10.N.0-2 with room for it to grow).
 */
class PrivateSubnet implements Deletable, Resource
{
    use ResolvesTags;

    /**
     * Where the private tier's /24s start inside the VPC's /16 — the public
     * tier owns 10.N.0-2, so starting at .10 leaves it room to grow.
     */
    protected const int CIDR_OFFSET = 10;

    public function __construct(protected int $index) {}

    /**
     * Subnet IDs for all three private subnets, in AZ order — the shape the RDS
     * DB subnet group expects.
     *
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
     * The /24 this subnet would occupy — best-effort at plan time (surfaced as
     * a Change by the sync step), re-resolved authoritatively at create. On a
     * greenfield plan pass the VPC doesn't exist yet either, so the carve
     * falls back to the /16 the VPC sync will claim — the plan must survive
     * "nothing exists", never throw (the two-pass contract).
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
     * Delete this subnet by id. Assumes upstream teardown has already removed
     * everything in it — the database was never YOLO's to delete and blocks the
     * whole network reclaim while it lives, so by the time this runs the subnet
     * is empty and the route-table association goes with it. A concurrent
     * removal (InvalidSubnetID.NotFound) is tolerated.
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

    /**
     * Carve this subnet's /24 from the VPC's /16 — YOLO owns the network, so
     * every VPC holds a `10.N.0.0/16` and the carve is always deterministic.
     */
    protected function carveFrom(string $vpcCidrBlock): string
    {
        $block = Str::before($vpcCidrBlock, '.0.0/16');

        return "{$block}." . (self::CIDR_OFFSET + $this->index) . '.0/24';
    }
}
