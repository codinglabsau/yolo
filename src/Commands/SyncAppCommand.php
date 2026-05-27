<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;

/**
 * Writer of one app's resources within an environment. Blast radius: this app.
 * Mode-aware (solo vs multi-tenant) and `--tenant`-filterable for a single-tenant
 * cutover. Assumes the environment tier exists — depends on shared resources and
 * additively attaches (listener rule, SNI cert), never mutating them.
 *
 * Two env-shared resources are provisioned here by exception rather than in
 * sync:environment: the RDS security group (its real work is this app's task-SG
 * ingress) and the HTTPS listener (its creation needs this app's ACM cert).
 * Both are created-if-missing and never mutated, so single-writer still holds.
 */
class SyncAppCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:app')
            ->setDescription('Sync a single application\'s resources for the given environment');
    }

    public function domains(): array
    {
        return [
            'Storage' => [
                Steps\Sync\App\SyncS3ArtefactBucketStep::class,
                Steps\Sync\App\SyncS3BucketStep::class,
                Steps\Sync\App\SyncS3AssetBucketStep::class,
            ],
            'IAM (app)' => [
                Steps\Sync\App\SyncMediaConvertRoleStep::class,
                Steps\Sync\App\AttachMediaConvertRolePoliciesStep::class,
                Steps\Sync\App\SyncDeployerPolicyStep::class,
                Steps\Sync\App\SyncDeployerRoleStep::class,
                Steps\Sync\App\AttachDeployerRolePoliciesStep::class,
            ],
            // The cert/DNS group runs before Fargate so the SSL certificate exists
            // before the HTTPS listener that needs it (solo only — multi-tenant has
            // no env-level apex, so the listener skips and certs attach per tenant).
            ...Manifest::isMultitenanted()
                ? [
                    'Landlord' => [
                        Steps\Sync\App\Landlord\SyncQueueStep::class,
                        Steps\Sync\App\Landlord\SyncQueueAlarmStep::class,
                    ],
                    'Tenants' => [
                        Steps\Sync\App\Tenant\SyncQueueStep::class,
                        Steps\Sync\App\Tenant\SyncQueueAlarmStep::class,
                    ],
                ]
                : [
                    'Solo' => [
                        Steps\Sync\App\Solo\SyncHostedZoneStep::class,
                        Steps\Sync\App\Solo\SyncSslCertificateStep::class,
                        Steps\Sync\App\Solo\SyncQueueStep::class,
                        Steps\Sync\App\Solo\SyncQueueAlarmStep::class,
                    ],
                ],
            ...Manifest::has('tasks.web')
                ? [
                    'Fargate' => [
                        Steps\Sync\App\SyncEcrRepositoryStep::class,
                        Steps\Sync\App\SyncEcsClusterStep::class,
                        Steps\Sync\App\SyncTaskSecurityGroupStep::class,
                        Steps\Sync\App\SyncRdsSecurityGroupStep::class,
                        Steps\Sync\App\SyncTargetGroupStep::class,
                        Steps\Sync\App\SyncHttpsListenerStep::class,
                        Steps\Sync\App\SyncListenerRuleStep::class,
                        Steps\Sync\App\SyncTaskLogGroupStep::class,
                        Steps\Sync\App\SyncTaskDefinitionStep::class,
                        Steps\Sync\App\SyncEcsServiceStep::class,
                    ],
                    'CDN' => [
                        Steps\Sync\App\SyncAssetDistributionStep::class,
                    ],
                ]
                : [],
            'Logging' => [
                Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
                Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
                Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
            ],
        ];
    }
}
