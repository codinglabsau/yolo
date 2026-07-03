<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\ServiceDefinition;
use Codinglabs\Yolo\Contracts\PlansSequentially;
use Codinglabs\Yolo\Concerns\ConfirmsDestruction;

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
class DestroyAppCommand extends SyncSteppedCommand implements PlansSequentially
{
    use ConfirmsDestruction;

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
     *
     * Services no longer refuse outright — destroy:app reverses each service's
     * per-app resources (see {@see appServiceTeardownSteps()}). The only service
     * stop left is the honest one: a service with per-app resources whose teardown
     * isn't modelled yet would orphan them, so it's named and refused.
     *
     * Public so the {@see DestroyCommand} orchestrator can apply the same guard to
     * the app it tears down before it touches the environment.
     */
    public function unsupportedReason(): ?string
    {
        return match (true) {
            Manifest::isMultitenanted() => 'destroy:app does not yet support multi-tenant apps — their per-tenant queues and SNI certificates would be left behind.',
            Manifest::isHeadless() => 'destroy:app does not yet support headless apps (no domain / ALB).',
            ! Manifest::hasWeb() => 'destroy:app only supports apps with a web task today.',
            ($unmodelled = static::servicesWithoutTeardown()) !== [] => sprintf(
                'destroy:app cannot yet tear down the per-app resources for: %s. Remove the service(s) from yolo.yml and deploy first.',
                implode(', ', $unmodelled),
            ),
            default => null,
        };
    }

    /**
     * The services this app uses that create per-app resources (appSteps) but
     * model no teardown for them — tearing the app down would orphan those, so
     * they're refused. Empty for every service today; a new service with appSteps
     * and no teardownAppSteps trips it until its reverse steps are written.
     *
     * @return array<int, string>
     */
    protected static function servicesWithoutTeardown(): array
    {
        return array_values(array_map(
            fn (ServiceDefinition $definition): string => $definition->service()->value,
            array_filter(
                Service::definitions(),
                fn (ServiceDefinition $definition): bool => Manifest::usesService($definition->service())
                    && $definition->appSteps() !== []
                    && $definition->teardownAppSteps() === [],
            ),
        ));
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
                Steps\Destroy\App\TeardownCloudFrontAssetDistributionStep::class,
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
                // Per-app service resources (e.g. the Typesense node-SG ingress this
                // app added) before the task SG / task role they hang off — each
                // service's reverse steps, self-gating on the app's own usage.
                ...static::appServiceTeardownSteps(),
                // Revoke shared-SG ingress before deleting the task SG those rules
                // reference. The cache revoke self-skips when the app has no cache SG.
                Steps\Destroy\App\RevokeCacheIngressStep::class,
                Steps\Destroy\App\RevokeRdsIngressStep::class,
                Steps\Destroy\App\RevokeExternalDatabaseIngressStep::class,
                Steps\Destroy\App\TeardownTaskSecurityGroupStep::class,
                Steps\Destroy\App\TeardownEcsTaskRoleStep::class,
                Steps\Destroy\App\TeardownEcsTaskPolicyStep::class,
                Steps\Destroy\App\DetachSslCertificateStep::class,
                Steps\Destroy\App\TeardownQueueStep::class,
                Steps\Destroy\App\WithdrawAppDnsRecordsStep::class,
                Steps\Destroy\App\TeardownDeployersGroupStep::class,
                Steps\Destroy\App\TeardownAppObserversGroupStep::class,
                Steps\Destroy\App\TeardownDeployerRoleStep::class,
                Steps\Destroy\App\TeardownAppObserverRoleStep::class,
                Steps\Destroy\App\TeardownDeployerPolicyStep::class,
                Steps\Destroy\App\TeardownAppObserverPolicyStep::class,
                Steps\Destroy\App\UnpublishAppManifestStep::class,
                // This app's per-app env file in the (env-shared) env config bucket —
                // its build env channel, which also held any minted Typesense keys.
                Steps\Destroy\App\RemoveAppEnvFileStep::class,
                Steps\Destroy\App\TeardownS3AssetBucketStep::class,
                Steps\Destroy\App\TeardownS3ConfigBucketStep::class,
                Steps\Destroy\App\TeardownEcrRepositoryStep::class,
                // Final act: stop yolo.yml advertising an environment whose resources
                // are now gone — surgical, format-preserving, warns if it can't.
                Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class,
            ])),
        ];
    }

    /**
     * Every used service's per-app teardown steps, composed from the definitions
     * in enum order — the mirror of SyncAppCommand's app service composition. Each
     * step self-gates, so composing them all is safe; a service the app never used
     * tears nothing down.
     *
     * @return array<int, class-string>
     */
    protected static function appServiceTeardownSteps(): array
    {
        $steps = [];

        foreach (Service::definitions() as $definition) {
            $steps = [...$steps, ...$definition->teardownAppSteps()];
        }

        return $steps;
    }
}
