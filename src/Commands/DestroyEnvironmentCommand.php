<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Lifecycle;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\error;

/**
 * Tears down an environment's shared "Tier A" resources — the compute/edge layer:
 * the env-backed service stacks (Typesense/IVS), the WAF, the load balancer +
 * listeners + its security group, the shared Valkey cache, the SNS alarm topic,
 * the shared exec role + the observer/admin IAM tiers, and the env buckets. The
 * reverse of
 * `sync:environment`, behind the same plan → confirm → apply runner.
 *
 * Tier B — the network shell (VPC, subnets, route tables, the RDS security group +
 * subnet group) — is deliberately LEFT STANDING: a surviving RDS instance lives in
 * it, and YOLO never touches a database it doesn't own. "Decommission the compute
 * tier, keep the data" is the likely 90% case; full VPC reclamation is a separate,
 * gated step.
 *
 * Guarded: refuses while any app still claims the environment (a published claim
 * file or running tasks) — tear those down with `destroy:app` first, so the shared
 * resources never go out from under a live app. The env-backed services come down
 * via their existing sync Teardown branches, forced on by the {@see Destroying}
 * flag for the duration of the run.
 *
 * The env config + logs buckets hold data (the env manifest + shared .env, the ALB
 * access logs), so they're only emptied and deleted with `--delete-data`; otherwise
 * they're left standing.
 */
class DestroyEnvironmentCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->addOption('delete-data', null, InputOption::VALUE_NONE, 'Also empty and delete the env config + logs buckets (irreversible data loss)')
            ->setName('destroy:environment')
            ->setDescription('Permanently tear down an environment\'s shared compute/edge resources (leaves the network shell + database)');
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
        return sprintf('Permanently delete the shared compute/edge resources for %s? This cannot be undone.', $environment);
    }

    #[\Override]
    protected function completionVerb(): string
    {
        return 'Destroyed';
    }

    /**
     * Tier A teardown in reverse-dependency order: the env-backed service stacks
     * first (their listener rules + target groups hang off the shared ALB), then
     * the WAF + listeners + load balancer + its security group, the SNS topic, the
     * shared exec role and the observer/admin IAM tiers, and finally the env
     * buckets. Tier B (the VPC and everything the database pins) is never listed.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            'environment' => [
                // Env-backed service stacks (Typesense/IVS): the Destroying flag
                // forces each service's lifecycle to Teardown, so these reuse the
                // sync steps' Teardown branches, ordered for teardown by the service.
                ...static::environmentServiceTeardownSteps(),
                // WAF off the ALB before either goes (a web ACL can't be deleted
                // while associated), then the listeners + load balancer, then the
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
                // The shared Valkey cache (env-owned, bootstrapped by sync:app): the
                // replication group first — its delete waits for completion — then the
                // subnet/parameter groups + security group it pinned.
                Steps\Destroy\Environment\TeardownCacheClusterStep::class,
                Steps\Destroy\Environment\TeardownCacheSecurityGroupStep::class,
                Steps\Destroy\Environment\TeardownCacheSubnetGroupStep::class,
                Steps\Destroy\Environment\TeardownCacheParameterGroupStep::class,
                Steps\Destroy\Environment\TeardownSnsAlarmTopicStep::class,
                // Shared identity: the exec role, then grant groups → tier roles →
                // tier policies (each delete() self-detaches; reverse of create order
                // keeps it tidy).
                Steps\Destroy\Environment\TeardownEcsExecutionRoleStep::class,
                Steps\Destroy\Environment\TeardownAdminsGroupStep::class,
                Steps\Destroy\Environment\TeardownObserversGroupStep::class,
                Steps\Destroy\Environment\TeardownAdminRoleStep::class,
                Steps\Destroy\Environment\TeardownObserverRoleStep::class,
                Steps\Destroy\Environment\TeardownAdminPolicyStep::class,
                Steps\Destroy\Environment\TeardownObserverPolicyStep::class,
                // Storage last, gated on --delete-data. Logs before the config
                // bucket, whose deletion is the final act of the env teardown.
                Steps\Destroy\Environment\TeardownEnvLogsBucketStep::class,
                Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class,
            ],
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
