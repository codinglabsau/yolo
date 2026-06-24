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
 * Tears an environment all the way down — the reverse of `sync:environment`, behind
 * the same plan → confirm → apply runner. First the shared "Tier A" compute/edge
 * layer (the env-backed service stacks, the WAF, the load balancer + listeners + its
 * security group, the shared Valkey cache, the SNS alarm topic, the shared exec role
 * + observer/admin IAM tiers, the env buckets), then "Tier B" — the network shell
 * (VPC, subnets, route table, internet gateway, RDS security group + subnet group).
 *
 * The network is reclaimed automatically once nothing else needs it. The one thing
 * that holds it back is a database: a surviving RDS instance lives in the VPC's
 * private subnets and pins the whole network, and YOLO NEVER deletes a database it
 * doesn't own — so a live DB leaves the network shell standing and is named in the
 * summary (snapshot + drop the DB out-of-band to fully reclaim). RDS is never touched.
 *
 * Guarded: refuses while any app still claims the environment (a published claim file
 * or running tasks) — tear those down with `destroy:app` first, so the shared
 * resources never go out from under a live app. The env-backed services come down via
 * their existing sync Teardown branches, forced on by the {@see Destroying} flag for
 * the duration of the run.
 *
 * The env config + logs buckets (the env manifest + shared .env, the ALB access logs)
 * are regeneratable infrastructure config, so they go with the environment. The
 * bring-your-own app data bucket and the database are never touched — the database
 * isn't YOLO's, and the data bucket isn't even Deletable.
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
     * Run standalone. Tearing down an environment shouldn't depend on the app still
     * declaring it — under the normal flow `destroy:app` has already removed the
     * yolo.yml block by now. So when the environment isn't in the local manifest,
     * reconstruct its config from the live account (STS account-id, profile region,
     * the published env manifest in S3) before the manifest checks run. When it IS
     * still declared, the declared block is used unchanged.
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

        // Force every env-backed service to Teardown for the duration, so the
        // service stacks come down even though the env manifest still declares them.
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
     * Tier A (compute/edge), then Tier B (the network shell) — reclaimed unless a
     * database is attached to the VPC (see {@see ReclaimsNetwork::networkSteps()}) —
     * then the local yolo.yml block, dropped dead last so the teardown above it can
     * still read the environment's account/region out of the manifest.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            'environment' => [
                ...static::tierASteps(),
                ...$this->networkSteps(),
                // The IAM tier goes dead last, on base credentials — it deletes the
                // role + policy this run is authenticated under, so it can't run any
                // earlier or under the assumed tier (see iamTierTeardownSteps).
                ...static::iamTierTeardownSteps(),
            ],
            // The manifest block is dropped after every AWS resource is gone (a
            // standalone run whose block was already removed SKIPs) — a local-file
            // change kept in its own scope so it never reads or writes live AWS.
            'manifest' => [
                Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class,
            ],
        ];
    }

    /**
     * The refusal summary: the network shell line when a database keeps it standing.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function warnings(): array
    {
        return $this->networkWarnings();
    }

    /**
     * The databases YOLO will never delete, named in the confirmation banner — the
     * live RDS instances attached to this environment's VPC.
     *
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return $this->liveDatabases();
    }

    /**
     * Tier A (compute/edge) teardown in reverse-dependency order: the env-backed
     * service stacks first (their listener rules + target groups hang off the
     * shared ALB), then the WAF + listeners + load balancer + its security group,
     * the Valkey cache, the SNS topic, and finally the env buckets. The IAM tier is
     * torn down separately, dead last and on base credentials (see
     * iamTierTeardownSteps). Shared with the destroy orchestrator.
     *
     * @return array<int, class-string>
     */
    public static function tierASteps(): array
    {
        return [
            // Env-backed service stacks (Typesense/IVS): the Destroying flag forces
            // each service's lifecycle to Teardown, so these reuse the sync steps'
            // Teardown branches, ordered for teardown by the service.
            ...static::environmentServiceTeardownSteps(),
            // WAF off the ALB before either goes (a web ACL can't be deleted while
            // associated), then the listeners + load balancer, then the
            // now-unreferenced web ACL + its IP sets.
            Steps\Destroy\Environment\DisassociateWafStep::class,
            Steps\Destroy\Environment\TeardownHttpsListenerStep::class,
            Steps\Destroy\Environment\TeardownHttpListenerStep::class,
            Steps\Destroy\Environment\TeardownLoadBalancerStep::class,
            Steps\Destroy\Environment\TeardownWebAclStep::class,
            Steps\Destroy\Environment\TeardownAllowIpSetStep::class,
            Steps\Destroy\Environment\TeardownBlockIpSetStep::class,
            // The LB security group, once the load balancer that used it is gone.
            Steps\Destroy\Environment\TeardownLoadBalancerSecurityGroupStep::class,
            // The shared Valkey cache: the replication group first — its delete
            // waits for completion — then the subnet/parameter groups + SG it pinned.
            Steps\Destroy\Environment\TeardownCacheClusterStep::class,
            Steps\Destroy\Environment\TeardownCacheSecurityGroupStep::class,
            Steps\Destroy\Environment\TeardownCacheSubnetGroupStep::class,
            Steps\Destroy\Environment\TeardownCacheParameterGroupStep::class,
            Steps\Destroy\Environment\TeardownSnsAlarmTopicStep::class,
            // Storage last: the env logs bucket, then the env config bucket, whose
            // deletion is the final act of the env (Tier A) teardown. The IAM tier
            // (exec role + observer/admin) is NOT here — it deletes the very role the
            // run is authenticated as, so it goes dead last on base credentials
            // (see iamTierTeardownSteps).
            Steps\Destroy\Environment\TeardownEnvLogsBucketStep::class,
            Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class,
        ];
    }

    /**
     * The IAM-tier teardown, run **dead last** (after every resource it grants the
     * permission to delete) and on the operator's **base credentials** (every step
     * is {@see RunsOnBaseCredentials}). The run assumed
     * the env admin role for its MFA gate, so it can't delete that role + AdminPolicy
     * under the very session they authorise — detaching AdminPolicy mid-run would
     * strip the permissions the teardown still needs. Order: the shared exec role,
     * then grant groups → tier roles → tier policies (each delete() self-detaches;
     * reverse of create order is tidy). Shared with the destroy orchestrator.
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
     * Tier B (the network shell) teardown in reverse-dependency order, only ever
     * reached once no database is attached to the VPC: the RDS subnet group + SG
     * first, then the public subnets, the route table, the internet gateway
     * (detached then deleted) and, last, the VPC itself.
     *
     * @return array<int, class-string>
     */
    public static function tierBSteps(): array
    {
        return [
            Steps\Destroy\Environment\TeardownRdsSubnetStep::class,
            Steps\Destroy\Environment\TeardownRdsSecurityGroupStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetAStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetBStep::class,
            Steps\Destroy\Environment\TeardownPublicSubnetCStep::class,
            Steps\Destroy\Environment\TeardownRouteTableStep::class,
            Steps\Destroy\Environment\TeardownInternetGatewayStep::class,
            Steps\Destroy\Environment\TeardownVpcStep::class,
        ];
    }

    /**
     * Every env-backed service's environment-tier teardown steps, composed from the
     * definitions in enum order — the mirror of SyncEnvironmentCommand's service
     * composition. Each runs its sync Teardown branch (forced by {@see Destroying}).
     *
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
