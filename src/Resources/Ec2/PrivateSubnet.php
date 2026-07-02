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
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One of the three private subnets (one per availability zone), addressed by AZ
 * index 0-2 — the database tier. No public IPs and no internet route: their
 * route table carries only the VPC-local route, so nothing in them is ever
 * reachable from outside the VPC. On a YOLO-created VPC each gets a /24 carved
 * deterministically from the VPC's /16 (`10.N.{10 + index}.0/24` — offset past
 * the public tier's 10.N.0-2 with room for it to grow); on an adopted VPC the
 * free /24s are discovered from the live subnet layout at create time, and
 * resolved by Name tag ever after. Point `private-subnets` at three existing
 * subnet names to adopt instead.
 */
class PrivateSubnet implements Deletable, Resource
{
    use ResolvesTags;

    /**
     * Where the private tier's /24s start inside a YOLO-created /16 — the
     * public tier owns 10.N.0-2, so starting at .10 leaves it room to grow.
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
        if (Manifest::has('private-subnets')) {
            return Manifest::get('private-subnets')[$this->index];
        }

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
            'CidrBlock' => $this->cidrBlock($vpc),
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
            return $this->cidrBlock(Ec2::vpc((new Vpc())->name()));
        } catch (ResourceDoesNotExistException) {
            $block = Str::before((new Vpc())->availableCidrBlock(), '.0.0/16');

            return "{$block}." . (self::CIDR_OFFSET + $this->index) . '.0/24';
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
     * The /24 for this subnet. A YOLO-created VPC always holds a `10.N.0.0/16`,
     * so the carve is deterministic (`10.N.{10 + index}.0/24`). An adopted VPC's
     * layout is whatever the owner built, so the index-th free /24 inside its
     * CIDR is discovered instead.
     *
     * @param  array<string, mixed>  $vpc
     */
    protected function cidrBlock(array $vpc): string
    {
        if (! Manifest::has('vpc')) {
            $block = Str::before($vpc['CidrBlock'], '.0.0/16');

            return "{$block}." . (self::CIDR_OFFSET + $this->index) . '.0/24';
        }

        return $this->discoveredCidrBlock($vpc);
    }

    /**
     * The index-th free /24 inside an adopted VPC's CIDR, diffed against every
     * live subnet except this tier's own — so the plan pass (nothing created
     * yet) and each sequential create land on the same three blocks: sibling A
     * occupying its slot doesn't shift sibling B onto a different candidate.
     * Only used until the subnets exist; thereafter they resolve by Name tag,
     * so the plan output is identical run-to-run.
     *
     * @param  array<string, mixed>  $vpc
     */
    protected function discoveredCidrBlock(array $vpc): string
    {
        $tierNames = collect(PrivateSubnets::cases())
            ->map(fn (PrivateSubnets $case): string => $this->keyedName($case->value))
            ->all();

        $cidrsInUse = collect(Aws::ec2()->describeSubnets([
            'Filters' => [['Name' => 'vpc-id', 'Values' => [$vpc['VpcId']]]],
        ])['Subnets'] ?? [])
            ->reject(fn (array $subnet): bool => in_array(
                collect($subnet['Tags'] ?? [])->firstWhere('Key', 'Name')['Value'] ?? null,
                $tierNames,
                true,
            ))
            ->pluck('CidrBlock')
            ->all();

        [$vpcStart, $vpcEnd] = Vpc::cidrRange($vpc['CidrBlock']);
        $free = 0;

        for ($base = $vpcStart; $base + 255 <= $vpcEnd; $base += 256) {
            $candidate = long2ip($base) . '/24';

            if (collect($cidrsInUse)->contains(fn (string $cidr): bool => Vpc::cidrsOverlap($candidate, $cidr))) {
                continue;
            }

            if ($free === $this->index) {
                return $candidate;
            }

            $free++;
        }

        throw new IntegrityCheckException(sprintf(
            'No free /24 left in %s for %s — the adopted VPC\'s CIDR is fully allocated.',
            $vpc['CidrBlock'],
            $this->name(),
        ));
    }
}
