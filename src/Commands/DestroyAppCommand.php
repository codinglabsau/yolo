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
 * App-scoped only: env-shared resources the app attaches to are revoked-from /
 * detached-from, never deleted. RDS and the BYO app data bucket are out of scope by
 * design. A tenant's hosted zone and ACM certificate are that tenant's domain-level
 * infrastructure, so teardown withdraws YOLO's use of them and leaves the domain
 * intact. Shapes whose teardown isn't fully modelled are refused outright rather
 * than torn down partially — see {@see unsupportedReason()}.
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
     * A partial teardown would orphan resources, so an unsupported shape is refused
     * rather than half-deleted. Public so {@see DestroyCommand} can apply the same
     * guard before it touches the environment.
     */
    public function unsupportedReason(): ?string
    {
        return match (true) {
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
     * A new service with appSteps and no teardownAppSteps trips this until its
     * reverse steps are written.
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
     * Reverse dependency order, gated on the same manifest predicates the sync
     * counterparts use so a config only tears down what it created.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            'app' => array_values(array_filter([
                Steps\Destroy\App\TeardownCloudWatchDashboardStep::class,
                Steps\Destroy\App\TeardownWebAlertAlarmStep::class,
                Steps\Destroy\App\TeardownCloudFrontAssetDistributionStep::class,
                // Autoscaling before the service it scales.
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
                Steps\Destroy\App\Tenant\TeardownForwardRuleStep::class,
                Steps\Destroy\App\Tenant\TeardownRedirectRuleStep::class,
                Steps\Destroy\App\TeardownTargetGroupStep::class,
                Steps\Destroy\App\TeardownTaskLogGroupStep::class,
                // Per-app service resources (e.g. a node-SG ingress) before the task
                // SG / task role they hang off.
                ...static::appServiceTeardownSteps(),
                // Revoke shared-SG ingress before deleting the task SG those rules reference.
                Steps\Destroy\App\RevokeCacheIngressStep::class,
                Steps\Destroy\App\RevokeRdsIngressStep::class,
                Steps\Destroy\App\RevokeExternalDatabaseIngressStep::class,
                Steps\Destroy\App\TeardownTaskSecurityGroupStep::class,
                Steps\Destroy\App\TeardownEcsTaskRoleStep::class,
                Steps\Destroy\App\TeardownEcsTaskPolicyStep::class,
                Steps\Destroy\App\DetachSslCertificateStep::class,
                Steps\Destroy\App\Tenant\DetachSslCertificateStep::class,
                // Each queue branch self-skips on the shape it isn't, so all three compose.
                Steps\Destroy\App\TeardownQueueStep::class,
                Steps\Destroy\App\Landlord\TeardownQueueStep::class,
                Steps\Destroy\App\Tenant\TeardownQueueStep::class,
                Steps\Destroy\App\WithdrawAppDnsRecordsStep::class,
                Steps\Destroy\App\Tenant\WithdrawDnsRecordsStep::class,
                Steps\Destroy\App\TeardownDeployersGroupStep::class,
                Steps\Destroy\App\TeardownAppObserversGroupStep::class,
                Steps\Destroy\App\TeardownDeployerRoleStep::class,
                Steps\Destroy\App\TeardownAppObserverRoleStep::class,
                Steps\Destroy\App\TeardownDeployerPolicyStep::class,
                Steps\Destroy\App\TeardownAppObserverPolicyStep::class,
                Steps\Destroy\App\UnpublishAppManifestStep::class,
                Steps\Destroy\App\RemoveAppEnvFileStep::class,
                Steps\Destroy\App\TeardownS3AssetBucketStep::class,
                Steps\Destroy\App\TeardownS3ConfigBucketStep::class,
                Steps\Destroy\App\TeardownEcrRepositoryStep::class,
                Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class,
            ])),
        ];
    }

    /**
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
