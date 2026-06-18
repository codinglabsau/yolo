<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Lifecycle;

/**
 * Writer of env-shared (environment-tier) resources — one set per environment, shared by
 * every app in it (VPC, subnets, ALB, shared IAM roles). Blast radius: all apps
 * in the environment. Apps depend on these and additively attach, but never
 * mutate them.
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
        return static::idleServiceWarnings();
    }

    /**
     * A heads-up for an env-backed service this environment provisions (declared
     * in the env manifest) that no running app currently consumes — you're
     * paying for an idle cluster. Not a gate: provisioning follows declaration,
     * so a declared-but-unused service is a legitimate (if costly) state, and
     * removing the manifest entry is what tears it down. Suppressed while a live
     * app hasn't published its services yet, since we can't then be sure the
     * service is truly unused.
     *
     * @return array<int, string>
     */
    public static function idleServiceWarnings(): array
    {
        // Only env-backed services the env manifest declares can sit idle. This
        // gate is cheap (the manifest is read once per run anyway) and keeps the
        // common sync — no env-backed service declared — off the registry/ECS
        // reads the consumer probe below needs.
        $declared = array_values(array_filter(
            Service::cases(),
            fn (Service $service): bool => $service->definition()->envBacked()
                && EnvManifest::has($service->envManifestKey()),
        ));

        if ($declared === []) {
            return [];
        }

        // A live app that hasn't republished its services yet might be a
        // consumer we can't see — don't cry "idle" while that's unknown.
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
                // network
                Steps\Sync\Environment\SyncVpcStep::class,
                Steps\Sync\Environment\SyncInternetGatewayStep::class,
                Steps\Sync\Environment\SyncInternetGatewayAttachmentStep::class,
                Steps\Sync\Environment\SyncPublicSubnetAStep::class,
                Steps\Sync\Environment\SyncPublicSubnetBStep::class,
                Steps\Sync\Environment\SyncPublicSubnetCStep::class,
                Steps\Sync\Environment\SyncRdsSubnetStep::class,
                Steps\Sync\Environment\SyncRouteTableStep::class,
                Steps\Sync\Environment\SyncDefaultRouteStep::class,
                Steps\Sync\Environment\SyncPublicSubnetsAssociationToRouteTableStep::class,
                Steps\Sync\Environment\SyncLoadBalancerSecurityGroupStep::class,
                Steps\Sync\Environment\SyncSnsAlarmTopicStep::class,
                // shared IAM — the ECS execution role (ECR pull + log write) is
                // generic and identical for every app, so it stays env-shared. The
                // task role is per-app (sync:app) so each app's runtime grants stay
                // its own.
                Steps\Sync\Environment\SyncEcsExecutionRoleStep::class,
                Steps\Sync\Environment\AttachEcsExecutionRolePoliciesStep::class,
                // env-shared read-only inspection policy (yolo-{env}-observer): the
                // drift-check surface every app's deployer role attaches so the
                // pre-deploy `sync --check` gate can read the whole stack under the
                // deploy role, scoped to exactly the services YOLO provisions.
                Steps\Sync\Environment\SyncObserverPolicyStep::class,
                // The read-only role an operator/agent assumes for safe inspection
                // (LPX-635) — created, then the observer policy attached to it.
                Steps\Sync\Environment\SyncObserverRoleStep::class,
                Steps\Sync\Environment\AttachObserverRolePolicyStep::class,
                // The Admin tier (LPX-680): the write surface (yolo-{env}-admin)
                // and the role an operator assumes to run `yolo sync` / `yolo scale`
                // capped to YOLO's blast radius. The role carries the observer
                // (read) + admin (write) policies. Self-activating — the first sync
                // creates these on the profile, every sync after mints the role.
                Steps\Sync\Environment\SyncAdminPolicyStep::class,
                Steps\Sync\Environment\SyncAdminRoleStep::class,
                Steps\Sync\Environment\AttachAdminRolePolicyStep::class,
                // Grant groups (LPX-680): membership is the access lever. The
                // env-wide observers + admins groups each allow sts:AssumeRole on
                // their tier role; YOLO owns the group + policy, never membership.
                Steps\Sync\Environment\SyncObserversGroupStep::class,
                Steps\Sync\Environment\SyncAdminsGroupStep::class,
                // env config bucket + the environment's declaration. The bucket
                // holds the env manifest (yolo-environment-{environment}.yml) and the env-shared .env;
                // the manifest is seeded exactly once, then owned by the operator
                // (edited via environment:manifest:push) — sync only ever converges
                // toward it, never rewrites it.
                Steps\Sync\Environment\SyncEnvConfigBucketStep::class,
                Steps\Sync\Environment\SeedEnvManifestStep::class,
                // env-backed services — each definition composes its own
                // ordered steps, every one gated on the env-backed service
                // lifecycle (declared in the env manifest). The same steps tear
                // the service down when the declaration is removed, so the plan
                // stays declared either way.
                ...static::environmentServiceSteps(),
                // env logs bucket (ALB access logs under alb/) — provisioned
                // before the load balancer so the log-delivery bucket policy
                // already grants the ELB service principal `s3:PutObject` when
                // `SyncLoadBalancerStep` enables access logs (AWS verifies the
                // policy at attribute-write time).
                Steps\Sync\Environment\SyncS3LogsBucketStep::class,
                // load balancer + :80 listener
                Steps\Sync\Environment\SyncLoadBalancerStep::class,
                Steps\Sync\Environment\SyncHttpListenerStep::class,
                // WAF (opt-in via `waf: true`) — the IP sets are referenced by the
                // web ACL's rules, so they're created first; the ACL is then bound
                // to the load balancer. Inert unless the manifest enables it.
                Steps\Sync\Environment\SyncWafAllowIpSetStep::class,
                Steps\Sync\Environment\SyncWafBlockIpSetStep::class,
                Steps\Sync\Environment\SyncWafWebAclStep::class,
                Steps\Sync\Environment\SyncWafAssociationStep::class,
            ],
        ];
    }

    /**
     * Every env-backed service's environment-tier steps, composed from the
     * definitions in enum order.
     *
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
