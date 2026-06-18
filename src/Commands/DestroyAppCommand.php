<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;

use function Laravel\Prompts\error;

/**
 * Tears down one app's resources in an environment — the reverse of `sync:app`.
 * Reuses the same plan → confirm → apply runner: a plan pass shows every
 * resource that WOULD DELETE, the confirm gate guards the irreversible apply,
 * and the apply pass deletes in declaration order, which here is reverse
 * dependency order (CDN → services → cluster → rules → target group → SGs →
 * IAM → buckets → ECR).
 *
 * App-scoped only. Env-shared resources the app attaches to are never deleted —
 * the RDS / cache security groups keep their group (only this app's ingress rule
 * is revoked) and the shared :443 listener keeps standing (only this app's rule
 * + SNI cert are removed). RDS, the BYO app data bucket, and env/account-scoped
 * infrastructure are out of scope by design.
 *
 * Configurations whose teardown isn't fully modelled yet are refused outright
 * rather than torn down partially (which would orphan resources) — see
 * {@see unsupportedReason()}.
 */
class DestroyAppCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('destroy:app')
            ->setDescription('Permanently tear down a single application\'s resources in the given environment');
    }

    #[\Override]
    public function handle(): int
    {
        if (($reason = $this->unsupportedReason()) !== null) {
            error($reason);

            return self::FAILURE;
        }

        return parent::handle();
    }

    /**
     * Why this app can't be torn down by `destroy:app` yet, or null when it can.
     * Each refusal is a deliberate safety stop: a partial teardown would leave
     * orphaned resources behind (which `yolo audit` then flags), so an
     * unsupported shape is refused rather than half-deleted.
     */
    protected function unsupportedReason(): ?string
    {
        return match (true) {
            Manifest::isMultitenanted() => 'destroy:app does not yet support multi-tenant apps — their per-tenant queues and SNI certificates would be left behind. Tracked in LPX-695.',
            Manifest::isHeadless() => 'destroy:app does not yet support headless apps (no domain / ALB). Tracked in LPX-695.',
            ! Manifest::hasWeb() => 'destroy:app only supports apps with a web task today. Tracked in LPX-695.',
            Manifest::services() !== [] => sprintf(
                'destroy:app does not yet tear down env-service resources (%s) — the app\'s per-service IAM and keys would be orphaned. Remove the service(s) from yolo.yml and deploy first, or wait for service-aware teardown. Tracked in LPX-695.',
                implode(', ', Manifest::services()),
            ),
            default => null,
        };
    }

    #[\Override]
    protected function planHeading(): string
    {
        return 'Will destroy';
    }

    #[\Override]
    protected function confirmQuestion(string $environment): string
    {
        return sprintf('Permanently delete these resources from %s? This cannot be undone.', $environment);
    }

    #[\Override]
    protected function completionVerb(): string
    {
        return 'Destroyed';
    }

    /**
     * The teardown steps in reverse dependency order. Mirrors `sync:app`'s app
     * scope, inverted: edge/compute first, then the network + identity it sat on,
     * then storage. Env-shared resources are revoked-from / detached-from, never
     * deleted. The conditional steps gate on the same manifest predicates their
     * sync counterparts do, so a config only tears down what it created.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            'app' => array_values(array_filter([
                Steps\Destroy\App\TeardownCloudWatchDashboardStep::class,
                Steps\Destroy\App\TeardownAssetDistributionStep::class,
                // Autoscaling before the service it scales: burst (policy + its
                // standalone alarm), then the scalable target (cascades the rest).
                Steps\Destroy\App\DeregisterWebBurstStep::class,
                Steps\Destroy\App\DeregisterWebAutoscalingStep::class,
                Manifest::hasStandaloneQueue() ? Steps\Destroy\App\DeregisterQueueAutoscalingStep::class : null,
                Manifest::hasStandaloneScheduler() ? Steps\Destroy\App\TeardownSchedulerServiceStep::class : null,
                Manifest::hasStandaloneQueue() ? Steps\Destroy\App\TeardownQueueServiceStep::class : null,
                Steps\Destroy\App\TeardownWebServiceStep::class,
                Steps\Destroy\App\DeregisterTaskDefinitionsStep::class,
                Steps\Destroy\App\TeardownEcsClusterStep::class,
                // Listener rules before the target group their action references.
                Steps\Destroy\App\TeardownForwardRuleStep::class,
                Steps\Destroy\App\TeardownRedirectRuleStep::class,
                Steps\Destroy\App\TeardownTargetGroupStep::class,
                Steps\Destroy\App\TeardownTaskLogGroupStep::class,
                // Revoke shared-SG ingress before deleting the task SG those rules
                // reference. The cache revoke self-skips when the app has no cache SG.
                Steps\Destroy\App\RevokeCacheIngressStep::class,
                Steps\Destroy\App\RevokeRdsIngressStep::class,
                Steps\Destroy\App\TeardownTaskSecurityGroupStep::class,
                Steps\Destroy\App\TeardownEcsTaskRoleStep::class,
                Steps\Destroy\App\TeardownEcsTaskPolicyStep::class,
                Steps\Destroy\App\TeardownSslCertificateStep::class,
                Steps\Destroy\App\TeardownQueueAlarmStep::class,
                Steps\Destroy\App\TeardownQueueStep::class,
                Steps\Destroy\App\TeardownHostedZoneStep::class,
                Steps\Destroy\App\TeardownDeployersGroupStep::class,
                Steps\Destroy\App\TeardownAppObserversGroupStep::class,
                Steps\Destroy\App\TeardownDeployerRoleStep::class,
                Steps\Destroy\App\TeardownAppObserverRoleStep::class,
                Steps\Destroy\App\TeardownDeployerPolicyStep::class,
                Steps\Destroy\App\TeardownAppObserverPolicyStep::class,
                Steps\Destroy\App\UnpublishAppManifestStep::class,
                Steps\Destroy\App\TeardownAssetBucketStep::class,
                Steps\Destroy\App\TeardownS3ConfigBucketStep::class,
                Steps\Destroy\App\TeardownEcrRepositoryStep::class,
            ])),
        ];
    }
}
