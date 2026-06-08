<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;

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

    #[\Override]
    public function handle(): int
    {
        if ($advisory = static::schedulerAdvisory()) {
            warning($advisory);
        }

        return parent::handle();
    }

    /**
     * A soft, non-blocking nudge (not a guard) when the scheduler is bundled into a
     * host that can run more than one task — the autoscaling web container, or the
     * standalone queue (which always autoscales). Cron then fires on every replica,
     * so every scheduled task must use ->onOneServer(). A dedicated tasks.scheduler
     * service is a pinned singleton and needs no nudge.
     */
    public static function schedulerAdvisory(): ?string
    {
        $host = Manifest::schedulerHost();

        $hostAutoscales = match ($host) {
            ServerGroup::WEB => Manifest::has('tasks.web.autoscaling'),
            ServerGroup::QUEUE => true, // a standalone queue is always autoscaled (min↔max)
            ServerGroup::SCHEDULER => false, // dedicated singleton — never multi-fires
        };

        if (! $hostAutoscales) {
            return null;
        }

        return sprintf(
            'The scheduler is bundled into the autoscaling %s task — cron fires on every replica. Guard each scheduled task with ->onOneServer(), or extract the scheduler into its own tasks.scheduler service.',
            $host->value,
        );
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
                        // Per-app ECS task role (the container runtime identity for
                        // web/queue/scheduler) + its baseline policy + any
                        // manifest-declared `task-role-policies` — created before the
                        // task definition that references the role ARN.
                        Steps\Sync\App\SyncEcsTaskPolicyStep::class,
                        Steps\Sync\App\SyncEcsTaskRoleStep::class,
                        Steps\Sync\App\AttachEcsTaskRolePoliciesStep::class,
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
                        // scales. Wired whenever the web task exists, not just when
                        // autoscaling is on, so removing the tasks.web.autoscaling
                        // block tears the scalable target, policies and their alarms
                        // back down. Both steps no-op when it was never enabled.
                        Steps\Sync\App\SyncScalableTargetStep::class,
                        Steps\Sync\App\SyncScalingPoliciesStep::class,
                        // Standalone queue service (own task-def + service +
                        // scale-to-zero autoscaling) — only when tasks.queue extracts
                        // it from the web container.
                        ...Manifest::hasStandaloneQueue()
                            ? [
                                Steps\Sync\App\SyncQueueTaskDefinitionStep::class,
                                Steps\Sync\App\SyncQueueServiceStep::class,
                                Steps\Sync\App\SyncQueueScalableTargetStep::class,
                                Steps\Sync\App\SyncQueueScalingPolicyStep::class,
                                Steps\Sync\App\SyncQueueScaleToZeroAlarmStep::class,
                            ]
                            : [],
                        // Standalone scheduler service (pinned singleton) — only when
                        // tasks.scheduler extracts it from the web container.
                        ...Manifest::hasStandaloneScheduler()
                            ? [
                                Steps\Sync\App\SyncSchedulerTaskDefinitionStep::class,
                                Steps\Sync\App\SyncSchedulerServiceStep::class,
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
