<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

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
                // env config bucket + the environment's declaration. The bucket
                // holds the env manifest (yolo-{environment}.yml) and the env-shared .env;
                // the manifest is seeded exactly once, then owned by the operator
                // (edited via environment:manifest:push) — sync only ever converges
                // toward it, never rewrites it.
                Steps\Sync\Environment\SyncEnvConfigBucketStep::class,
                Steps\Sync\Environment\SeedEnvManifestStep::class,
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
}
