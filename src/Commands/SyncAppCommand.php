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
                Steps\Storage\SyncS3ArtefactBucketStep::class,
                Steps\Storage\SyncS3BucketStep::class,
                Steps\Storage\SyncS3AssetBucketStep::class,
            ],
            'IAM (app)' => [
                Steps\Iam\SyncMediaConvertRoleStep::class,
                Steps\Iam\AttachMediaConvertRolePoliciesStep::class,
                Steps\Iam\SyncDeployerPolicyStep::class,
                Steps\Iam\SyncDeployerRoleStep::class,
                Steps\Iam\AttachDeployerRolePoliciesStep::class,
            ],
            // The cert/DNS group runs before Fargate so the SSL certificate exists
            // before the HTTPS listener that needs it (solo only — multi-tenant has
            // no env-level apex, so the listener skips and certs attach per tenant).
            ...Manifest::isMultitenanted()
                ? [
                    'Landlord' => [
                        Steps\Landlord\SyncQueueStep::class,
                        Steps\Landlord\SyncQueueAlarmStep::class,
                    ],
                    'Tenants' => [
                        Steps\Tenant\SyncQueueStep::class,
                        Steps\Tenant\SyncQueueAlarmStep::class,
                    ],
                ]
                : [
                    'Solo' => [
                        Steps\Solo\SyncHostedZoneStep::class,
                        Steps\Solo\SyncSslCertificateStep::class,
                        Steps\Solo\SyncQueueStep::class,
                        Steps\Solo\SyncQueueAlarmStep::class,
                    ],
                ],
            ...Manifest::has('tasks.web')
                ? [
                    'Fargate' => [
                        Steps\Fargate\SyncEcrRepositoryStep::class,
                        Steps\Fargate\SyncEcsClusterStep::class,
                        Steps\Fargate\SyncTaskSecurityGroupStep::class,
                        Steps\Network\SyncRdsSecurityGroupStep::class,
                        Steps\Fargate\SyncTargetGroupStep::class,
                        Steps\Fargate\SyncHttpsListenerStep::class,
                        Steps\Fargate\SyncListenerRuleStep::class,
                        Steps\Fargate\SyncTaskLogGroupStep::class,
                        Steps\Fargate\SyncTaskDefinitionStep::class,
                        Steps\Fargate\SyncEcsServiceStep::class,
                    ],
                    'CDN' => [
                        Steps\CloudFront\SyncAssetDistributionStep::class,
                    ],
                ]
                : [],
            'Logging' => [
                Steps\Logging\SyncIvsCloudWatchLogGroupStep::class,
                Steps\Logging\SyncIvsEventBridgeRuleStep::class,
                Steps\Logging\SyncIvsEventBridgeTargetStep::class,
            ],
        ];
    }
}
