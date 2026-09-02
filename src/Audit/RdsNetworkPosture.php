<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Aws\Ec2;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Rds\RdsSubnet;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Where the manifest-declared database lives relative to the YOLO network:
 * exposed (publicly accessible — a warning), managed (env VPC + private subnet
 * group + YOLO RDS SG) or external (anything else — valid, informational).
 * Audit-only, never sync drift: an externally-hosted database must not block
 * deploys.
 *
 * Every cross-service read degrades to null when denied or missing — the audit
 * may run under a tier that can't see EC2, and an unknown fact is never a warning.
 */
final readonly class RdsNetworkPosture
{
    public const string MANAGED = 'managed';

    public const string EXTERNAL = 'external';

    public const string EXPOSED = 'exposed';

    private function __construct(
        public ?string $classification,
        public ?string $vpcId,
        public ?bool $publiclyAccessible,
        public ?bool $taskIngress,
    ) {}

    public static function evaluate(RdsInspection $inspection): ?self
    {
        if (! $inspection->readable) {
            return null;
        }

        return new self(
            classification: self::classify($inspection),
            vpcId: $inspection->vpcId,
            publiclyAccessible: $inspection->publiclyAccessible,
            taskIngress: self::taskIngress($inspection->securityGroupIds, $inspection->port),
        );
    }

    protected static function classify(RdsInspection $inspection): ?string
    {
        if ($inspection->publiclyAccessible === true) {
            return self::EXPOSED;
        }

        // Unknown VPC on either side is not a verdict — never guess "external".
        if ($inspection->vpcId === null) {
            return null;
        }

        $environmentVpcId = self::environmentVpcId();

        if ($environmentVpcId === null) {
            return null;
        }

        if ($inspection->vpcId === $environmentVpcId
            && $inspection->subnetGroupName === (new RdsSubnet())->name()
            && in_array(self::rdsSecurityGroupId(), $inspection->securityGroupIds, true)) {
            return self::MANAGED;
        }

        return self::EXTERNAL;
    }

    /**
     * {@see AuthorisesTaskIngress} writes the rule on the managed path. Null when
     * it can't be determined — the task SG doesn't exist yet, or the rule
     * describes are denied under this tier.
     *
     * @param  array<int, string>  $securityGroupIds
     */
    protected static function taskIngress(array $securityGroupIds, ?int $port): ?bool
    {
        // No port (instance still creating) means unknown, never "none found".
        if ($securityGroupIds === [] || $port === null) {
            return null;
        }

        try {
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();

            foreach ($securityGroupIds as $securityGroupId) {
                // A hand-written rule on a peered database may be all-traffic (-1)
                // or a tcp range — either reaches the port, so neither should warn.
                if (collect(Ec2::securityGroupRules($securityGroupId))->contains(
                    fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                        && (($rule['IpProtocol'] ?? null) === '-1'
                            || (($rule['IpProtocol'] ?? null) === 'tcp'
                                && ($rule['FromPort'] ?? PHP_INT_MAX) <= $port
                                && ($rule['ToPort'] ?? PHP_INT_MIN) >= $port))
                        && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
                )) {
                    return true;
                }
            }

            return false;
        } catch (ResourceDoesNotExistException|Ec2Exception) {
            return null;
        }
    }

    protected static function environmentVpcId(): ?string
    {
        try {
            return (new Vpc())->arn();
        } catch (ResourceDoesNotExistException|Ec2Exception) {
            return null;
        }
    }

    protected static function rdsSecurityGroupId(): ?string
    {
        try {
            return (new RdsSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException|Ec2Exception) {
            return null;
        }
    }
}
