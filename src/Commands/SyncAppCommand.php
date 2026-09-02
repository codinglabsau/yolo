<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * Depends on the environment tier and additively attaches to it (listener rule, SNI
 * cert), never mutating shared resources. Two env-shared resources are bootstrapped
 * here by exception: the RDS security group (its real work is this app's task-SG
 * ingress) and the HTTPS listener (its creation needs this app's ACM cert). Both are
 * created-if-missing and never mutated, so single-writer still holds.
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
        if (! $this->ensureClaimedServicesOffered()) {
            return self::FAILURE;
        }

        if (! $this->ensureAppBucketAdoptable()) {
            return self::FAILURE;
        }

        return parent::handle();
    }

    #[\Override]
    public function warnings(): array
    {
        return array_filter([
            ...EnvironmentVersion::skewWarnings(),
            static::schedulerDisabledWarning(),
            static::schedulerAdvisory(),
            $this->hostedZoneOwnershipWarning(),
        ]);
    }

    /**
     * Not a gate: record writes are isolated (each env UPSERTs only its own
     * subdomain) and the env ownership tag is first-writer-wins.
     */
    public function hostedZoneOwnershipWarning(): ?string
    {
        if (! Manifest::hasDomain()) {
            return null;
        }

        $owner = (new HostedZone(Manifest::apex()))->ownerEnvironment();

        if ($owner === null) {
            return null;
        }

        return sprintf(
            'The hosted zone for %s is already owned by the "%s" environment. This app is served on the one '
                . 'domain from more than one environment — DNS records stay isolated (each env writes only its own '
                . 'subdomain) and YOLO leaves the existing yolo:environment tag in place.',
            Manifest::apex(),
            $owner,
        );
    }

    /**
     * Rarely intended — framework/package maintenance riding the scheduler silently
     * stops firing — so it's surfaced on every sync.
     */
    public static function schedulerDisabledWarning(): ?string
    {
        if (! Manifest::schedulerDisabled()) {
            return null;
        }

        return 'The scheduler is disabled (tasks.scheduler: false) — `schedule:run` runs nowhere. '
            . 'Scheduled tasks and framework/package maintenance (model pruning, auth:clear-resets, etc.) will not fire.';
    }

    /**
     * A nudge, not a guard: cron fires on every replica of a multi-task host, so
     * every scheduled task must use ->onOneServer().
     */
    public static function schedulerAdvisory(): ?string
    {
        $host = Manifest::schedulerHost();

        $hostAutoscales = match ($host) {
            ServerGroup::WEB => Manifest::autoscales(ServerGroup::WEB),
            ServerGroup::QUEUE => Manifest::autoscales(ServerGroup::QUEUE), // a fixed (autoscaling: false) queue won't multi-fire
            ServerGroup::SCHEDULER => false, // dedicated singleton — never multi-fires
            null => false, // disabled — surfaced by schedulerDisabledWarning instead
        };

        if (! $hostAutoscales) {
            return null;
        }

        return sprintf(
            'The scheduler is bundled into the autoscaling %s task. Use ->onOneServer() on scheduled tasks to avoid duplicate execution.',
            $host->value,
        );
    }

    public function scopes(): array
    {
        return [
            'app' => [
                Steps\Sync\App\SyncS3ConfigBucketStep::class,
                Steps\Sync\App\SyncS3BucketStep::class,
                Steps\Sync\App\SyncS3AssetBucketStep::class,
                // The env tier flags idle services and guards service removal off this
                // published claim.
                Steps\Sync\App\PublishAppManifestStep::class,
                // Always in the plan (each self-gates on the claim) so dropping a claim
                // melts that service's per-app IAM on the same sync instead of orphaning it.
                ...static::appServiceSteps(),
                // Every policy before any attach — the deployer attach references the
                // per-app observer policy. The observer is always provisioned (no
                // GitHub-repo gate) so a read grant can name a single app; it's also
                // the read surface the deployer carries for the pre-deploy sync-check.
                Steps\Sync\App\SyncDeployerPolicyStep::class,
                Steps\Sync\App\SyncAppObserverPolicyStep::class,
                Steps\Sync\App\SyncDeployerRoleStep::class,
                Steps\Sync\App\SyncAppObserverRoleStep::class,
                Steps\Sync\App\AttachDeployerRolePoliciesStep::class,
                Steps\Sync\App\AttachAppObserverRolePolicyStep::class,
                Steps\Sync\App\SyncDeployersGroupStep::class,
                Steps\Sync\App\SyncAppObserversGroupStep::class,
                // Before Fargate so the certificate exists before the HTTPS listener
                // that needs it. `tenants` is an orthogonal axis: a tenanted app on
                // its own domain gets the same app-level zone + cert a solo app does.
                ...Manifest::hasDomain()
                    ? [
                        Steps\Sync\App\Solo\SyncHostedZoneStep::class,
                        Steps\Sync\App\Solo\SyncSslCertificateStep::class,
                    ]
                    : [],
                // A tenant under the app's wildcard self-skips (Manifest::servesDomain),
                // so declaring tenants for their queues costs no DNS/TLS resources.
                ...Manifest::hasTenants()
                    ? [
                        Steps\Sync\App\Tenant\SyncHostedZoneStep::class,
                        Steps\Sync\App\Tenant\SyncSslCertificateStep::class,
                    ]
                    : [],
                // Gated on tenants, not the mode — with none declared there is one
                // scope, so the solo branch (melt included) is the correct shape.
                ...Manifest::hasTenants()
                    ? (Manifest::fansQueuesPerTenant()
                        ? [
                            Steps\Sync\App\Landlord\SyncQueueStep::class,
                            Steps\Sync\App\Tenant\SyncQueueStep::class,
                        ]
                        : [
                            Steps\Sync\App\Shared\SyncQueueStep::class,
                        ])
                    : [
                        // With no worker anywhere jobs run inline (QUEUE_CONNECTION=sync)
                        // and the queue is never published to — tear it down rather than
                        // strand it. Multi-tenant queues stay unconditional: their
                        // per-tenant teardown is the unbuilt destroy:app gap.
                        ...Manifest::queueHost() instanceof ServerGroup
                            ? [
                                Steps\Sync\App\Solo\SyncQueueStep::class,
                            ]
                            : [
                                Steps\Destroy\App\TeardownQueueStep::class,
                            ],
                    ],
                // Gated on "at least one ECS service", not on web — the standalone
                // queue/scheduler need Fargate too.
                ...Manifest::serverGroups() !== []
                    ? [
                        Steps\Sync\App\SyncEcrRepositoryStep::class,
                        Steps\Sync\App\SyncEcsClusterStep::class,
                        // Task role before the task definition that references its ARN.
                        Steps\Sync\App\SyncEcsTaskPolicyStep::class,
                        Steps\Sync\App\SyncEcsTaskRoleStep::class,
                        Steps\Sync\App\AttachEcsTaskRolePoliciesStep::class,
                        Steps\Sync\App\SyncTaskSecurityGroupStep::class,
                        Steps\Sync\App\SyncRdsSecurityGroupStep::class,
                        // Skipped by the deploy gate — its tier may not hold the RDS /
                        // foreign-SG reads.
                        Steps\Sync\App\SyncExternalDatabaseIngressStep::class,
                        // Valkey is env-owned but bootstrapped from sync:app (gated on
                        // cache.store); the app then authorises its own 6379 ingress on
                        // the shared SG, mirroring Typesense's env-SG/app-ingress split.
                        Steps\Sync\Environment\SyncCacheSubnetGroupStep::class,
                        Steps\Sync\Environment\SyncCacheParameterGroupStep::class,
                        Steps\Sync\Environment\SyncCacheSecurityGroupStep::class,
                        Steps\Sync\Environment\SyncCacheClusterStep::class,
                        Steps\Sync\App\AuthoriseCacheIngressStep::class,
                        Steps\Sync\App\SyncTaskLogGroupStep::class,
                        // Always-wired melt: dropping the block would otherwise just prune
                        // the sync steps and strand a live service the plan never mentions
                        // again (running service + stale scalable target + non-cascading
                        // scale-to-zero alarm). Each teardown no-ops when nothing's live.
                        ...Manifest::hasStandaloneQueue()
                            ? [
                                Steps\Sync\App\SyncQueueTaskDefinitionStep::class,
                                Steps\Sync\App\SyncQueueServiceStep::class,
                                Steps\Sync\App\SyncQueueScalableTargetStep::class,
                                Steps\Sync\App\SyncQueueScalingPolicyStep::class,
                                Steps\Sync\App\SyncQueueScaleToZeroAlarmStep::class,
                            ]
                            : [
                                // Autoscaling before the service — mirrors destroy:app.
                                Steps\Destroy\App\DeregisterQueueAutoscalingStep::class,
                                Steps\Destroy\App\TeardownQueueServiceStep::class,
                            ],
                        ...Manifest::hasStandaloneScheduler()
                            ? [
                                Steps\Sync\App\SyncSchedulerTaskDefinitionStep::class,
                                Steps\Sync\App\SyncSchedulerServiceStep::class,
                            ]
                            : [
                                Steps\Destroy\App\TeardownSchedulerServiceStep::class,
                            ],
                    ]
                    : [],
                ...Manifest::hasWeb()
                    ? [
                        Steps\Sync\App\SyncTargetGroupStep::class,
                        Steps\Sync\App\SyncHttpsListenerStep::class,
                        Steps\Sync\App\SyncForwardRuleStep::class,
                        Steps\Sync\App\SyncRedirectRuleStep::class,
                        // After the listener they attach to and the target group they
                        // forward at. Each self-skips for a tenant the app's own
                        // certificate already covers.
                        ...Manifest::hasTenants()
                            ? [
                                Steps\Sync\App\Tenant\AttachSslCertificateToLoadBalancerListenerStep::class,
                                Steps\Sync\App\Tenant\SyncForwardRuleStep::class,
                                Steps\Sync\App\Tenant\SyncRedirectRuleStep::class,
                            ]
                            : [],
                        Steps\Sync\App\SyncTaskDefinitionStep::class,
                        Steps\Sync\App\SyncEcsServiceStep::class,
                        // Wired whenever the web task exists, not just when autoscaling
                        // is on, so removing the block tears the scalable target,
                        // policies, burst policy and their alarms back down.
                        Steps\Sync\App\SyncScalableTargetStep::class,
                        Steps\Sync\App\SyncScalingPoliciesStep::class,
                        Steps\Sync\App\SyncWebBurstStep::class,
                        Steps\Sync\App\SyncCloudFrontAssetDistributionStep::class,
                    ]
                    : [],
                // Last so every resource it charts already exists.
                Steps\Sync\App\SyncWebAlertAlarmStep::class,
                Steps\Sync\App\SyncCloudWatchDashboardStep::class,
            ],
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected static function appServiceSteps(): array
    {
        $steps = [];

        foreach (Service::definitions() as $definition) {
            $steps = [...$steps, ...$definition->appSteps()];
        }

        return $steps;
    }
}
