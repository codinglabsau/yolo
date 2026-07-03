<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

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
        // A claim on a service the env manifest doesn't offer is a hard error
        // here, exactly as at build/deploy — the claim would publish cleanly
        // and then provision nothing.
        if (! $this->ensureClaimedServicesOffered()) {
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
     * A heads-up when this app's hosted zone is already owned by another
     * environment — i.e. the same app is served on the one domain from more than
     * one env (a trial alongside prod). It's not a gate: record writes are
     * isolated (each env UPSERTs only its own subdomain) and the env ownership tag
     * is first-writer-wins, so this only reminds the operator the zone is shared.
     */
    public function hostedZoneOwnershipWarning(): ?string
    {
        if (Manifest::isMultitenanted() || Manifest::isHeadless()) {
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
     * A loud warning when cron is switched off entirely (`tasks.scheduler: false`):
     * the Laravel scheduler runs nowhere, so scheduled work and the framework/package
     * maintenance that rides the scheduler (model pruning, auth:clear-resets,
     * telescope/pulse pruning, …) silently stop firing. Rarely intended, so it's
     * surfaced on every sync as a deliberate choice rather than a quiet default.
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
     * A soft, non-blocking nudge (not a guard) when the scheduler is bundled into a
     * host that can run more than one task — the autoscaling web container, or the
     * standalone queue (which always autoscales). Cron then fires on every replica,
     * so every scheduled task must use ->onOneServer(). A dedicated tasks.scheduler
     * service is a pinned singleton and needs no nudge; a disabled scheduler (null
     * host) never fires at all, so it gets the separate warning above, not this one.
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
                // storage
                Steps\Sync\App\SyncS3ConfigBucketStep::class,
                Steps\Sync\App\SyncS3BucketStep::class,
                Steps\Sync\App\SyncS3AssetBucketStep::class,
                // environment claim — publish `apps/{app}.yml` into the env
                // config bucket so the env tier can flag idle services and
                // guard service removal (deploy republishes it too)
                Steps\Sync\App\PublishAppManifestStep::class,
                // per-service app resources — every service's app steps are
                // always in the plan (each self-gates on the app's claim), so
                // dropping a claim melts that service's per-app IAM on the
                // same sync rather than orphaning it
                ...static::appServiceSteps(),
                // app IAM — every policy is created before any attach, so the
                // deployer attach can reference the per-app observer policy.
                // The per-app observer (read tier scoped to one app, log content
                // fenced to its log group) is always provisioned (no GitHub-repo
                // gate) so a read grant can name a single app; it's also the read
                // surface the deployer carries for the pre-deploy sync-check gate.
                Steps\Sync\App\SyncDeployerPolicyStep::class,
                Steps\Sync\App\SyncAppObserverPolicyStep::class,
                Steps\Sync\App\SyncDeployerRoleStep::class,
                Steps\Sync\App\SyncAppObserverRoleStep::class,
                Steps\Sync\App\AttachDeployerRolePoliciesStep::class,
                Steps\Sync\App\AttachAppObserverRolePolicyStep::class,
                // per-app grant groups: membership grants deploy / read
                // on THIS app only. The deployers group is gated on a GitHub repo
                // like the deployer role it points at; the observers group is always
                // provisioned so a read grant can name a single app.
                Steps\Sync\App\SyncDeployersGroupStep::class,
                Steps\Sync\App\SyncAppObserversGroupStep::class,
                // cert/DNS + queues — runs before Fargate so the SSL certificate
                // exists before the HTTPS listener that needs it. Solo gets an
                // env-level apex zone + cert; multi-tenant has none (certs attach
                // per tenant via SNI), so it fans out landlord + per-tenant queues.
                ...Manifest::isMultitenanted()
                    ? [
                        Steps\Sync\App\Landlord\SyncQueueStep::class,
                        Steps\Sync\App\Tenant\SyncQueueStep::class,
                    ]
                    : [
                        Steps\Sync\App\Solo\SyncHostedZoneStep::class,
                        Steps\Sync\App\Solo\SyncSslCertificateStep::class,
                        // The SQS queue, always wired with a melt branch:
                        // `tasks.queue: false` runs jobs inline
                        // (QUEUE_CONNECTION=sync), so the queue is never published to
                        // — tear it down instead of stranding an idle queue (mirrors
                        // the standalone-service melt below).
                        // (Multi-tenant queues stay unconditional — their per-tenant
                        // teardown is the unbuilt destroy:app gap.)
                        ...Manifest::queueDisabled()
                            ? [
                                Steps\Destroy\App\TeardownQueueStep::class,
                            ]
                            : [
                                Steps\Sync\App\Solo\SyncQueueStep::class,
                            ],
                    ],
                // Fargate + CDN (web tasks only)
                ...Manifest::hasWeb()
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
                        // An externally-hosted (peered) database gets the same
                        // additive 3306-from-task-SG rule on its own discovered
                        // security group; skipped by the deploy gate (its tier
                        // may not hold the RDS / foreign-SG reads).
                        Steps\Sync\App\SyncExternalDatabaseIngressStep::class,
                        // Valkey cache — env-owned, bootstrapped from sync:app (gated
                        // on cache.store). The env infrastructure (subnet/parameter
                        // groups, the SG, the cluster) lives in the Environment
                        // namespace; this app then authorises its own 6379 ingress on
                        // the shared SG below, mirroring Typesense's env-SG/app-ingress
                        // split.
                        Steps\Sync\Environment\SyncCacheSubnetGroupStep::class,
                        Steps\Sync\Environment\SyncCacheParameterGroupStep::class,
                        Steps\Sync\Environment\SyncCacheSecurityGroupStep::class,
                        Steps\Sync\Environment\SyncCacheClusterStep::class,
                        Steps\Sync\App\AuthoriseCacheIngressStep::class,
                        Steps\Sync\App\SyncTargetGroupStep::class,
                        Steps\Sync\App\SyncHttpsListenerStep::class,
                        Steps\Sync\App\SyncForwardRuleStep::class,
                        Steps\Sync\App\SyncRedirectRuleStep::class,
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
                        // Burst scale-out: a high-res worker-saturation alarm + step policy
                        // for ~10s spike detection — part of web autoscaling, not a setting.
                        // Wired whenever the web task exists so a non-autoscaling web tier
                        // prunes the policy + its self-authored alarm; no-ops when it doesn't apply.
                        Steps\Sync\App\SyncWebBurstStep::class,
                        // Standalone queue service (own task-def + service +
                        // scale-to-zero autoscaling) — only when tasks.queue extracts
                        // it from the web container. When it no longer does — the block
                        // reverted to bundled, or was switched off with
                        // `tasks.queue: false` — the melt branch tears any
                        // previously-extracted service + autoscaling back down. Without
                        // it, dropping the block would just prune the sync steps and
                        // strand a live service the plan never mentions again (a running
                        // service + a stale scalable target + the non-cascading
                        // scale-to-zero alarm). These are the same teardown units
                        // destroy:app runs; each no-ops when nothing's live, so a normal
                        // bundled-queue app pays only two idempotent reads per sync —
                        // the same always-wired-melt shape the web autoscaling steps use.
                        ...Manifest::hasStandaloneQueue()
                            ? [
                                Steps\Sync\App\SyncQueueTaskDefinitionStep::class,
                                Steps\Sync\App\SyncQueueServiceStep::class,
                                Steps\Sync\App\SyncQueueScalableTargetStep::class,
                                Steps\Sync\App\SyncQueueScalingPolicyStep::class,
                                Steps\Sync\App\SyncQueueScaleToZeroAlarmStep::class,
                            ]
                            : [
                                // Autoscaling first (scalable target + cascaded policies
                                // + the standalone alarm), then the service — mirrors
                                // destroy:app's teardown order.
                                Steps\Destroy\App\DeregisterQueueAutoscalingStep::class,
                                Steps\Destroy\App\TeardownQueueServiceStep::class,
                            ],
                        // Standalone scheduler service (pinned singleton) — only when
                        // tasks.scheduler extracts it from the web container; otherwise
                        // melt any previously-extracted scheduler service back down
                        // (the scheduler never autoscales, so the service is all there
                        // is to tear down).
                        ...Manifest::hasStandaloneScheduler()
                            ? [
                                Steps\Sync\App\SyncSchedulerTaskDefinitionStep::class,
                                Steps\Sync\App\SyncSchedulerServiceStep::class,
                            ]
                            : [
                                Steps\Destroy\App\TeardownSchedulerServiceStep::class,
                            ],
                        Steps\Sync\App\SyncCloudFrontAssetDistributionStep::class,
                    ]
                    : [],
                // observability — runs last so every resource it charts already exists
                Steps\Sync\App\SyncCloudWatchDashboardStep::class,
            ],
        ];
    }

    /**
     * Every service's app-tier steps, composed from the definitions in enum
     * order — the declared plan stays the same whatever the app claims; each
     * step gates itself on the claim (sync when claimed, melt when dropped).
     *
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
