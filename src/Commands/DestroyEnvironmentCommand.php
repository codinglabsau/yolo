<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\ReclaimsNetwork;
use Codinglabs\Yolo\Contracts\PlansSequentially;
use Codinglabs\Yolo\Concerns\ConfirmsDestruction;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;
use Codinglabs\Yolo\Concerns\BootstrapsEnvironmentFromAws;

use function Laravel\Prompts\error;

/**
 * Tier A (compute/edge) then Tier B (the network shell). YOLO NEVER deletes a
 * database: a surviving RDS instance pins the VPC's private subnets, so a live DB
 * leaves the network shell standing and is named in the summary. Refuses while any
 * app still claims the environment so shared resources never go out from under a
 * live app. The env config + logs buckets are regeneratable and go with the
 * environment; the BYO app data bucket isn't even Deletable.
 */
class DestroyEnvironmentCommand extends SyncSteppedCommand implements PlansSequentially
{
    use BootstrapsEnvironmentFromAws;
    use ConfirmsDestruction;
    use ReclaimsNetwork;

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('destroy:environment')
            ->setDescription('Permanently tear down an entire environment — compute, edge and network (the database is never touched)');
    }

    /**
     * Under the normal flow `destroy:app` has already removed the yolo.yml block, so
     * reconstruct the environment from the live account when it isn't declared.
     */
    #[\Override]
    protected function bootstrapEnvironment(): ?int
    {
        if (Manifest::environmentExists($this->argument('environment'))) {
            return null;
        }

        return $this->bootstrapEnvironmentFromAws($this->argument('environment'));
    }

    #[\Override]
    public function handle(): int
    {
        if (($claiming = Lifecycle::claimingApps()) !== []) {
            error(sprintf(
                'destroy:environment refuses while apps still claim %s: %s. Tear each down with `yolo destroy:app %s` first.',
                $this->argument('environment'),
                implode(', ', $claiming),
                $this->argument('environment'),
            ));

            return self::FAILURE;
        }

        // Forces every env-backed service to Teardown even though the env manifest
        // still declares them.
        return Destroying::during(fn (): int => parent::handle());
    }

    #[\Override]
    protected function planHeading(): string
    {
        return 'Will destroy';
    }

    #[\Override]
    protected function confirmQuestion(string $environment): string
    {
        return sprintf('Permanently delete every resource in the %s environment? This cannot be undone.', $environment);
    }

    #[\Override]
    protected function completionVerb(): string
    {
        return 'Destroyed';
    }

    /**
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            'environment' => [
                ...static::tierASteps(),
                ...$this->networkSteps(),
                ...static::iamTierTeardownSteps(),
            ],
            // Dropped dead last so the teardown above can still read the environment's
            // account/region out of the manifest; its own scope so it never touches AWS.
            'manifest' => [
                Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    #[\Override]
    public function warnings(): array
    {
        return $this->networkWarnings();
    }

    /**
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return $this->liveDatabases();
    }

    /**
     * Reverse-dependency order; the IAM tier is excluded (see iamTierTeardownSteps).
     * Shared with the destroy orchestrator.
     *
     * @return array<int, class-string>
     */
    public static function tierASteps(): array
    {
        return [
            // Service stacks first — their listener rules + target groups hang off the ALB.
            ...static::environmentServiceTeardownSteps(),
            // A web ACL can't be deleted while associated.
            Steps\Destroy\Environment\DisassociateWafStep::class,
            Steps\Destroy\Environment\TeardownHttpsListenerStep::class,
            Steps\Destroy\Environment\TeardownHttpListenerStep::class,
            Steps\Destroy\Environment\TeardownLoadBalancerStep::class,
            Steps\Destroy\Environment\TeardownWebAclStep::class,
            Steps\Destroy\Environment\TeardownAllowIpSetStep::class,
            Steps\Destroy\Environment\TeardownBlockIpSetStep::class,
            Steps\Destroy\Environment\TeardownWafLogGroupStep::class,
            Steps\Destroy\Environment\TeardownLoadBalancerSecurityGroupStep::class,
            Steps\Destroy\Environment\TeardownCacheClusterStep::class,
            Steps\Destroy\Environment\TeardownCacheSecurityGroupStep::class,
            Steps\Destroy\Environment\TeardownCacheSubnetGroupStep::class,
            Steps\Destroy\Environment\TeardownCacheParameterGroupStep::class,
            Steps\Destroy\Environment\TeardownAlertAlarmsStep::class,
            Steps\Destroy\Environment\TeardownSnsAlarmTopicStep::class,
            Steps\Destroy\Environment\TeardownEnvLogsBucketStep::class,
            Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class,
        ];
    }

    /**
     * Runs dead last and on base credentials (every step is {@see RunsOnBaseCredentials}):
     * the run assumed the env admin role for its MFA gate, and detaching AdminPolicy
     * mid-run would strip the permissions the teardown still needs. Shared with the
     * destroy orchestrator.
     *
     * @return array<int, class-string>
     */
    public static function iamTierTeardownSteps(): array
    {
        return [
            Steps\Destroy\Environment\TeardownEcsExecutionRoleStep::class,
            Steps\Destroy\Environment\TeardownAdminsGroupStep::class,
            Steps\Destroy\Environment\TeardownObserversGroupStep::class,
            Steps\Destroy\Environment\TeardownAdminRoleStep::class,
            Steps\Destroy\Environment\TeardownObserverRoleStep::class,
            Steps\Destroy\Environment\TeardownAdminPolicyStep::class,
            Steps\Destroy\Environment\TeardownObserverPolicyStep::class,
        ];
    }

    /**
     * Only reached once no database is attached to the VPC.
     *
     * @return array<int, class-string>
     */
    public static function tierBSteps(): array
    {
        return [
            // A peered VPC can't be deleted.
            Steps\Destroy\Environment\TeardownVpcPeeringConnectionsStep::class,
            Steps\Destroy\Environment\TeardownRdsSubnetStep::class,
            Steps\Destroy\Environment\TeardownRdsSecurityGroupStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetAStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetBStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetCStep::class,
            Steps\Destroy\Environment\TeardownPrivateSubnetAStep::class,
            Steps\Destroy\Environment\TeardownPrivateSubnetBStep::class,
            Steps\Destroy\Environment\TeardownPrivateSubnetCStep::class,
            Steps\Destroy\Environment\TeardownRouteTableStep::class,
            Steps\Destroy\Environment\TeardownPrivateRouteTableStep::class,
            Steps\Destroy\Environment\TeardownInternetGatewayStep::class,
            Steps\Destroy\Environment\TeardownVpcStep::class,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected static function environmentServiceTeardownSteps(): array
    {
        $steps = [];

        foreach (Service::definitions() as $definition) {
            $steps = [...$steps, ...$definition->teardownEnvironmentSteps()];
        }

        return $steps;
    }
}
