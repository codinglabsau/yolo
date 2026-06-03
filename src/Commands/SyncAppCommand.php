<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;

use function Laravel\Prompts\warning;

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

    public function handle(): int
    {
        if ($advisory = static::schedulerAdvisory()) {
            warning($advisory);
        }

        return parent::handle();
    }

    /**
     * A soft, non-blocking nudge (not a guard) when autoscaling is enabled on a
     * task that also runs the scheduler. Scaling the bundled task to N replicas
     * runs cron N times, so every scheduled task must use ->onOneServer(); apps
     * that outgrow that should separate the scheduler into its own service.
     */
    public static function schedulerAdvisory(): ?string
    {
        if (! Manifest::has('tasks.web.autoscaling') || empty(Manifest::get('tasks.web.scheduler'))) {
            return null;
        }

        return 'Autoscaling a bundled web+scheduler task: every scheduled task must use ->onOneServer() so it does not run on each replica. '
            . 'If a bundled cluster cannot scale cleanly, separate the scheduler into its own service.';
    }

    public function scopes(): array
    {
        return [
            'app' => [
                // storage
                Steps\Sync\App\SyncS3ArtefactBucketStep::class,
                Steps\Sync\App\SyncS3BucketStep::class,
                Steps\Sync\App\SyncS3AssetBucketStep::class,
                // app IAM (deployer + MediaConvert)
                Steps\Sync\App\SyncMediaConvertRoleStep::class,
                Steps\Sync\App\AttachMediaConvertRolePoliciesStep::class,
                Steps\Sync\App\SyncDeployerPolicyStep::class,
                Steps\Sync\App\SyncDeployerRoleStep::class,
                Steps\Sync\App\AttachDeployerRolePoliciesStep::class,
                // cert/DNS + queues — runs before Fargate so the SSL certificate
                // exists before the HTTPS listener that needs it. Solo gets an
                // env-level apex zone + cert; multi-tenant has none (certs attach
                // per tenant via SNI), so it fans out landlord + per-tenant queues.
                ...Manifest::isMultitenanted()
                    ? [
                        Steps\Sync\App\Landlord\SyncQueueStep::class,
                        Steps\Sync\App\Landlord\SyncQueueAlarmStep::class,
                        Steps\Sync\App\Tenant\SyncQueueStep::class,
                        Steps\Sync\App\Tenant\SyncQueueAlarmStep::class,
                    ]
                    : [
                        Steps\Sync\App\Solo\SyncHostedZoneStep::class,
                        Steps\Sync\App\Solo\SyncSslCertificateStep::class,
                        Steps\Sync\App\Solo\SyncQueueStep::class,
                        Steps\Sync\App\Solo\SyncQueueAlarmStep::class,
                    ],
                // Fargate + CDN (web tasks only)
                ...Manifest::has('tasks.web')
                    ? [
                        Steps\Sync\App\SyncEcrRepositoryStep::class,
                        Steps\Sync\App\SyncEcsClusterStep::class,
                        Steps\Sync\App\SyncTaskSecurityGroupStep::class,
                        Steps\Sync\App\SyncRdsSecurityGroupStep::class,
                        // Valkey cache (gated on cache) — env-shared, bootstrapped
                        // from sync:app like the RDS SG; the cache SG needs the task SG.
                        Steps\Sync\App\SyncCacheSubnetGroupStep::class,
                        Steps\Sync\App\SyncCacheParameterGroupStep::class,
                        Steps\Sync\App\SyncCacheSecurityGroupStep::class,
                        Steps\Sync\App\SyncCacheClusterStep::class,
                        Steps\Sync\App\SyncTargetGroupStep::class,
                        Steps\Sync\App\SyncHttpsListenerStep::class,
                        Steps\Sync\App\SyncListenerRuleStep::class,
                        Steps\Sync\App\SyncTaskLogGroupStep::class,
                        Steps\Sync\App\SyncTaskDefinitionStep::class,
                        Steps\Sync\App\SyncEcsServiceStep::class,
                        // Autoscaling (web only) — registered after the service it
                        // scales, and only when a tasks.web.autoscaling block opts in.
                        ...Manifest::has('tasks.web.autoscaling')
                            ? [
                                Steps\Sync\App\SyncScalableTargetStep::class,
                                Steps\Sync\App\SyncScalingPoliciesStep::class,
                            ]
                            : [],
                        Steps\Sync\App\SyncAssetDistributionStep::class,
                    ]
                    : [],
                // logging (IVS CloudWatch + EventBridge)
                Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
                Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
                Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
                // observability — runs last so every resource it charts already exists
                Steps\Sync\App\SyncCloudWatchDashboardStep::class,
            ],
        ];
    }
}
