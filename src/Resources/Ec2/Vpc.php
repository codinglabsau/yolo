<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared VPC for the environment (not per-app). On create it claims the lowest
 * `10.N.0.0/16` (from 10.1) that overlaps no VPC already in the region, so two
 * environments on the one account never share a range — they stay peerable and
 * there's no "which env is this 10.1 address?" confusion. 10.0 is skipped (a
 * co-located Vapor stack's block); a fresh account still lands on 10.1, the
 * previous fixed default. The CIDR is chosen once at create (the VPC is keyed by
 * Name, so sync never re-picks); the subnets carve their /24s from whatever block
 * it lands in. Point `vpc` at an existing VPC name to adopt rather than create
 * one; SyncVpcStep reports that as CUSTOM_MANAGED.
 */
class Vpc implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * The VPC attributes a Route 53 private hosted zone needs set to true to
     * resolve inside the VPC. A created VPC defaults enableDnsSupport on but
     * enableDnsHostnames OFF — and a private hosted zone (so Cloud Map private
     * DNS, the backing of ECS service discovery) requires BOTH. Keys are the
     * `modifyVpcAttribute` parameter names.
     */
    protected const array DNS_ATTRIBUTES = ['EnableDnsSupport', 'EnableDnsHostnames'];

    public function name(): string
    {
        return Manifest::get('vpc', $this->keyedName());
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ec2::vpc($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::vpc($this->name())['VpcId'];
    }

    public function create(): void
    {
        $vpcId = (string) Aws::ec2()->createVpc([
            'CidrBlock' => $this->availableCidrBlock(),
            'TagSpecifications' => [
                ['ResourceType' => 'vpc', ...Aws::tags($this->tags())],
            ],
        ])['Vpc']['VpcId'];

        // A created VPC defaults enableDnsHostnames OFF, which leaves any
        // Route 53 private hosted zone — and therefore Cloud Map private DNS,
        // the backing of ECS service discovery (e.g. Typesense's Raft peer
        // addresses) — unresolvable inside it. Turn both DNS attributes on at
        // create; synchroniseConfiguration() keeps them true thereafter.
        foreach (self::DNS_ATTRIBUTES as $attribute) {
            $this->enableDnsAttribute($vpcId, $attribute);
        }
    }

    /**
     * Reconcile the VPC's DNS attributes back to true. Both must be on for a
     * Route 53 private hosted zone to resolve inside the VPC, so Cloud Map
     * private DNS (ECS service discovery — e.g. the Typesense Raft peer list)
     * stays dark until they are. This also heals any VPC created before they
     * were set at create time. Reads regardless of $apply (so a dry-run reports
     * the drift) and writes only the attributes that are off.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $vpcId = $this->arn();
        $changes = [];

        foreach (Ec2::vpcDnsAttributes($vpcId) as $attribute => $enabled) {
            if ($enabled) {
                continue;
            }

            if ($apply) {
                $this->enableDnsAttribute($vpcId, $attribute);
            }

            $changes[] = Change::make($attribute, false, true);
        }

        return $changes;
    }

    /**
     * Set one boolean VPC DNS attribute to true — `modifyVpcAttribute` takes a
     * single attribute per call, so create and reconcile both funnel here.
     */
    protected function enableDnsAttribute(string $vpcId, string $attribute): void
    {
        Aws::ec2()->modifyVpcAttribute([
            'VpcId' => $vpcId,
            $attribute => ['Value' => true],
        ]);
    }

    /**
     * The lowest `10.N.0.0/16` (N from 1) that overlaps no VPC currently in the
     * region. Best-effort at plan time (re-resolved authoritatively here at
     * create); a fresh account with nothing in 10.x returns 10.1.0.0/16.
     */
    public function availableCidrBlock(): string
    {
        $inUse = Ec2::cidrBlocksInUse();

        foreach (range(1, 255) as $octet) {
            $candidate = "10.{$octet}.0.0/16";

            if (collect($inUse)->every(fn (string $cidr): bool => ! static::cidrsOverlap($candidate, $cidr))) {
                return $candidate;
            }
        }

        throw new IntegrityCheckException('No free 10.x.0.0/16 range for a new VPC — every block from 10.1 to 10.255 is in use in this region.');
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Whether two IPv4 CIDR blocks share any address, compared as integer ranges.
     * ip2long is masked to 32 bits so a high existing block can't sign-flip the
     * arithmetic; the candidate 10.x blocks are always well inside positive range.
     */
    protected static function cidrsOverlap(string $a, string $b): bool
    {
        [$startA, $endA] = static::cidrRange($a);
        [$startB, $endB] = static::cidrRange($b);

        return $startA <= $endB && $startB <= $endA;
    }

    /**
     * The inclusive [start, end] integer address range of a CIDR block.
     *
     * @return array{0: int, 1: int}
     */
    protected static function cidrRange(string $cidr): array
    {
        [$network, $prefix] = explode('/', $cidr);
        $hostBits = 32 - (int) $prefix;
        $base = (ip2long($network) & 0xFFFFFFFF) & (0xFFFFFFFF << $hostBits & 0xFFFFFFFF);

        return [$base, $base + (1 << $hostBits) - 1];
    }
}
