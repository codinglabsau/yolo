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
 * Classifies where the manifest-declared database actually lives relative to
 * the YOLO network — the audit-only companion to {@see RdsInspection}. It never
 * feeds sync drift: an externally-hosted database is a valid (transitional)
 * posture and must not block deploys, so the deploy gate's `sync --check`
 * never sees any of this.
 *
 *  - **exposed** — `PubliclyAccessible` is on: the database has a public
 *    endpoint regardless of which VPC it's in. Warning.
 *  - **managed** — the end-state: env VPC, the private DB subnet group, the
 *    YOLO RDS security group. Informational.
 *  - **external** — anything else (a different VPC, or hand-wired networking
 *    inside the env VPC): externally-managed, valid, informational. The
 *    transitional peered pattern lands here.
 *
 * Separately from the classification, the task-security-group reachability
 * check warns when no attached security group carries a 3306 ingress from the
 * app's task SG — the one rule that lets Fargate tasks reach the database (a
 * peered SG can reference it too, so the check applies to external databases
 * as well).
 *
 * Every cross-service read degrades to null (unknown) when it's denied or the
 * resource doesn't exist — the audit may run under a tier that can't see EC2,
 * and an unknown fact is never a warning.
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

    /**
     * Evaluate the posture of a readable inspection, or null when the database
     * couldn't be read at all — there is nothing to classify (the unreadable
     * warning already covers it).
     */
    public static function evaluate(RdsInspection $inspection): ?self
    {
        if (! $inspection->readable) {
            return null;
        }

        return new self(
            classification: self::classify($inspection),
            vpcId: $inspection->vpcId,
            publiclyAccessible: $inspection->publiclyAccessible,
            taskIngress: self::taskIngress($inspection->securityGroupIds),
        );
    }

    protected static function classify(RdsInspection $inspection): ?string
    {
        if ($inspection->publiclyAccessible === true) {
            return self::EXPOSED;
        }

        // No VPC on the DB record, or the env VPC can't be seen under this tier
        // → unknown, not a verdict — never guess a database into "external".
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
     * Whether any attached security group carries the 3306-from-task-SG ingress
     * rule ({@see AuthorisesTaskIngress} writes it on
     * the managed path). Null when it can't be determined — the task SG doesn't
     * exist yet, or the rule describes are denied under this tier.
     *
     * @param  array<int, string>  $securityGroupIds
     */
    protected static function taskIngress(array $securityGroupIds): ?bool
    {
        if ($securityGroupIds === []) {
            return null;
        }

        try {
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();

            foreach ($securityGroupIds as $securityGroupId) {
                // YOLO's own rule is exactly tcp/3306, but a hand-written rule on
                // a peered database may be all-traffic (-1) or a tcp range — any
                // of those reaches 3306, so none of them should warn.
                if (collect(Ec2::securityGroupRules($securityGroupId))->contains(
                    fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                        && (($rule['IpProtocol'] ?? null) === '-1'
                            || (($rule['IpProtocol'] ?? null) === 'tcp'
                                && ($rule['FromPort'] ?? PHP_INT_MAX) <= 3306
                                && ($rule['ToPort'] ?? PHP_INT_MIN) >= 3306))
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
