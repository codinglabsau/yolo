<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Services\Lifecycle;

/**
 * Blast radius: every app in the environment. Apps depend on these and additively
 * attach, but never mutate them.
 */
class SyncEnvironmentCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:environment')
            ->setDescription('Sync the env-shared (environment-tier) resources for the given environment');
    }

    #[\Override]
    public function warnings(): array
    {
        return [
            ...EnvironmentVersion::skewWarnings(),
            ...static::idleServiceWarnings(),
        ];
    }

    /**
     * Not a gate: provisioning follows declaration, so a declared-but-unused
     * service is a legitimate (if costly) state.
     *
     * @return array<int, string>
     */
    public static function idleServiceWarnings(): array
    {
        // Cheap gate that keeps the common sync (no env-backed service declared)
        // off the registry/ECS reads the consumer probe needs.
        $declared = array_values(array_filter(
            Service::cases(),
            fn (Service $service): bool => $service->definition()->envBacked()
                && EnvManifest::has($service->envManifestKey()),
        ));

        if ($declared === []) {
            return [];
        }

        // A live app that hasn't published its services yet might be a consumer we
        // can't see.
        if (Lifecycle::unpublishedLiveApps() !== []) {
            return [];
        }

        $warnings = [];

        foreach ($declared as $service) {
            if (Lifecycle::liveAppsUsing($service) !== []) {
                continue;
            }

            $warnings[] = sprintf(
                'The %s service is provisioned (declared in the environment manifest) but no running app uses it — '
                    . 'you\'re paying for it while it sits idle. Remove services.%s with `yolo environment:manifest:pull/push` if it\'s no longer needed.',
                $service->value,
                $service->value,
            );
        }

        return $warnings;
    }

    public function scopes(): array
    {
        return [
            'environment' => [
                Steps\Sync\Environment\SyncVpcStep::class,
                Steps\Sync\Environment\SyncInternetGatewayStep::class,
                Steps\Sync\Environment\SyncInternetGatewayAttachmentStep::class,
                Steps\Sync\Environment\SyncPublicSubnetAStep::class,
                Steps\Sync\Environment\SyncPublicSubnetBStep::class,
                Steps\Sync\Environment\SyncPublicSubnetCStep::class,
                // The private tier carries only the VPC-local route, so a database in
                // the RDS subnet group is unreachable from outside the VPC.
                Steps\Sync\Environment\SyncPrivateSubnetAStep::class,
                Steps\Sync\Environment\SyncPrivateSubnetBStep::class,
                Steps\Sync\Environment\SyncPrivateSubnetCStep::class,
                Steps\Sync\Environment\SyncRdsSubnetStep::class,
                Steps\Sync\Environment\SyncRouteTableStep::class,
                Steps\Sync\Environment\SyncDefaultRouteStep::class,
                Steps\Sync\Environment\SyncPublicSubnetsAssociationToRouteTableStep::class,
                Steps\Sync\Environment\SyncPrivateRouteTableStep::class,
                Steps\Sync\Environment\SyncPrivateSubnetsAssociationToRouteTableStep::class,
                // Peering DNS resolution goes dead last — it's the switch that sends
                // traffic across the bridge, so it must not flip until every route exists.
                Steps\Sync\Environment\SyncVpcPeeringStep::class,
                Steps\Sync\Environment\SyncVpcPeeringRoutesStep::class,
                Steps\Sync\Environment\SyncVpcPeeringDnsStep::class,
                Steps\Sync\Environment\SyncLoadBalancerSecurityGroupStep::class,
                Steps\Sync\Environment\SyncSnsAlarmTopicStep::class,
                // The execution role (ECR pull + log write) is identical for every app,
                // so it stays env-shared; the task role is per-app.
                Steps\Sync\Environment\SyncEcsExecutionRoleStep::class,
                Steps\Sync\Environment\AttachEcsExecutionRolePoliciesStep::class,
                // The observer policy is also the drift-check surface every app's
                // deployer role attaches for the pre-deploy `sync --check` gate.
                Steps\Sync\Environment\SyncObserverPolicyStep::class,
                Steps\Sync\Environment\SyncObserverRoleStep::class,
                Steps\Sync\Environment\AttachObserverRolePolicyStep::class,
                // Self-activating: the first sync creates the admin tier on the
                // profile, every sync after mints the role.
                Steps\Sync\Environment\SyncAdminPolicyStep::class,
                Steps\Sync\Environment\SyncAdminRoleStep::class,
                Steps\Sync\Environment\AttachAdminRolePolicyStep::class,
                // YOLO owns the grant groups + their policies, never membership.
                Steps\Sync\Environment\SyncObserversGroupStep::class,
                Steps\Sync\Environment\SyncAdminsGroupStep::class,
                // The env manifest is seeded exactly once, then operator-owned — sync
                // only ever converges toward it, never rewrites it.
                Steps\Sync\Environment\SyncEnvConfigBucketStep::class,
                Steps\Sync\Environment\SeedEnvManifestStep::class,
                // The same steps tear a service down when its declaration is removed,
                // so the plan stays declared either way.
                ...static::environmentServiceSteps(),
                // Before the load balancer: AWS verifies the log-delivery bucket policy
                // at attribute-write time when SyncLoadBalancerStep enables access logs.
                Steps\Sync\Environment\SyncS3LogsBucketStep::class,
                Steps\Sync\Environment\SyncS3BackupsBucketStep::class,
                Steps\Sync\Environment\SyncLoadBalancerStep::class,
                Steps\Sync\Environment\SyncHttpListenerStep::class,
                // The web ACL's rules reference the IP sets and its logging config the
                // log group, so all three come first.
                Steps\Sync\Environment\SyncWafAllowIpSetStep::class,
                Steps\Sync\Environment\SyncWafBlockIpSetStep::class,
                Steps\Sync\Environment\SyncWafLogGroupStep::class,
                Steps\Sync\Environment\SyncWafWebAclStep::class,
                Steps\Sync\Environment\SyncWafAssociationStep::class,
                // After the SNS topic they fire to and the load balancer whose ARN
                // suffix they dimension on.
                Steps\Sync\Environment\SyncAlertAlarmsStep::class,
                // Last on purpose: the version-of-record stamp only lands after the
                // rest of the tier has synced under the stamped release.
                Steps\Sync\Environment\SyncEnvironmentVersionStep::class,
            ],
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected static function environmentServiceSteps(): array
    {
        $steps = [];

        foreach (Service::definitions() as $definition) {
            $steps = [...$steps, ...$definition->environmentSteps()];
        }

        return $steps;
    }
}
