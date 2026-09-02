<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Env-shared. On create it claims the lowest `10.N.0.0/16` (from 10.1) overlapping
 * no VPC in the region, so two environments on one account never share a range and
 * stay peerable. 10.0 is skipped (a co-located Vapor stack's block). Chosen once —
 * the VPC is keyed by Name, so sync never re-picks; subnets carve from whatever it
 * lands in.
 */
class Vpc implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * A created VPC defaults enableDnsHostnames OFF, but a Route 53 private hosted
     * zone (Cloud Map private DNS, backing ECS service discovery) needs BOTH on.
     * Keys are the `modifyVpcAttribute` parameter names.
     */
    protected const array DNS_ATTRIBUTES = ['EnableDnsSupport', 'EnableDnsHostnames'];

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

        foreach (self::DNS_ATTRIBUTES as $attribute) {
            $this->enableDnsAttribute($vpcId, $attribute);
        }
    }

    /**
     * Also heals any VPC created before the DNS attributes were set at create.
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

    /** `modifyVpcAttribute` takes a single attribute per call. */
    protected function enableDnsAttribute(string $vpcId, string $attribute): void
    {
        Aws::ec2()->modifyVpcAttribute([
            'VpcId' => $vpcId,
            $attribute => ['Value' => true],
        ]);
    }

    /** Best-effort at plan time; re-resolved authoritatively at create. */
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

    /** Earlier destroy steps have emptied the network shell; AWS refuses with DependencyViolation otherwise. */
    public function delete(): void
    {
        try {
            Aws::ec2()->deleteVpc(['VpcId' => $this->arn()]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidVpcID.NotFound') {
                return;
            }

            throw $e;
        }
    }

    /** ip2long is masked to 32 bits so a high existing block can't sign-flip the arithmetic. */
    protected static function cidrsOverlap(string $a, string $b): bool
    {
        [$startA, $endA] = static::cidrRange($a);
        [$startB, $endB] = static::cidrRange($b);

        return $startA <= $endB && $startB <= $endA;
    }

    /**
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
