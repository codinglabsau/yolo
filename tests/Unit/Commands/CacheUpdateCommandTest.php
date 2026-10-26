<?php

declare(strict_types=1);

use Aws\Result;
use Carbon\Carbon;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\CacheTopology;
use Aws\ElastiCache\ElastiCacheClient;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\CacheUpdateCommand;
use Symfony\Component\Console\Output\BufferedOutput;

const CACHE_GROUP = 'yolo-testing-cache';

const CACHE_MUTATIONS = ['IncreaseReplicaCount', 'ModifyReplicationGroup', 'BatchApplyUpdateAction', 'DecreaseReplicaCount'];

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The cache's starting shape: one node, failover off. Overrides merge on top.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cacheWorld(array $overrides = []): array
{
    return [
        'status' => 'available',
        'members' => [CACHE_GROUP . '-001' => ['role' => 'primary', 'zone' => 'ap-southeast-2a']],
        'failover' => 'disabled',
        'multiAz' => 'disabled',
        // name => [released, status, statuses still to report once applied]
        'updates' => [],
        ...$overrides,
    ];
}

/**
 * @return array{0: string, 1: string, 2: array<int, string>}
 */
function cacheUpdate(string $released, string $status = 'not-applied', array $progression = ['waiting-to-start', 'in-progress', 'complete']): array
{
    return [$released, $status, $progression];
}

/**
 * Bind an ElastiCache client that models the replication group: replica
 * adds/removes, failover toggles, primary promotion, and update actions that
 * step through a progression once applied (failing over to the replica, as AWS
 * does when it patches the primary).
 *
 * @param  array<string, mixed>  $world
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindCacheWorld(array &$world, array &$captured): void
{
    $handler = new class($world, $captured) extends MockHandler
    {
        public function __construct(protected array &$world, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $args = $cmd->toArray();
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $args];
            $w = &$this->world;

            return Create::promiseFor(new Result(match ($cmd->getName()) {
                'DescribeReplicationGroups' => ['ReplicationGroups' => [$this->group()]],
                'DescribeCacheClusters' => ['CacheClusters' => [[
                    'CacheClusterId' => $args['CacheClusterId'],
                    'CacheClusterStatus' => 'available',
                    'PreferredAvailabilityZone' => $w['members'][$args['CacheClusterId']]['zone'] ?? 'ap-southeast-2a',
                    'CacheSubnetGroupName' => CACHE_GROUP . '-subnet-group',
                ]]],
                'DescribeCacheSubnetGroups' => ['CacheSubnetGroups' => [[
                    'CacheSubnetGroupName' => CACHE_GROUP . '-subnet-group',
                    'Subnets' => array_map(fn (string $zone): array => ['SubnetAvailabilityZone' => ['Name' => $zone]], ['ap-southeast-2c', 'ap-southeast-2a', 'ap-southeast-2b']),
                ]]],
                'IncreaseReplicaCount' => $this->addReplica($args),
                'ModifyReplicationGroup' => $this->modify($args),
                'DescribeUpdateActions' => ['UpdateActions' => $this->updateActions($args)],
                'BatchApplyUpdateAction' => $this->apply($args),
                'DecreaseReplicaCount' => $this->removeReplicas($args),
                default => [],
            }));
        }

        protected function group(): array
        {
            return [
                'ReplicationGroupId' => CACHE_GROUP,
                'Status' => $this->world['status'],
                'MemberClusters' => array_keys($this->world['members']),
                'AutomaticFailover' => $this->world['failover'],
                'MultiAZ' => $this->world['multiAz'],
                'NodeGroups' => [[
                    'NodeGroupId' => '0001',
                    'NodeGroupMembers' => array_map(
                        fn (string $id, array $member): array => ['CacheClusterId' => $id, 'CurrentRole' => $member['role'], 'PreferredAvailabilityZone' => $member['zone']],
                        array_keys($this->world['members']),
                        $this->world['members'],
                    ),
                ]],
            ];
        }

        protected function addReplica(array $args): array
        {
            $zones = $args['ReplicaConfiguration'][0]['PreferredAvailabilityZones'];
            $this->world['members'][CACHE_GROUP . '-002'] = ['role' => 'replica', 'zone' => $zones[1]];

            return [];
        }

        protected function modify(array $args): array
        {
            if (isset($args['AutomaticFailoverEnabled'])) {
                $this->world['failover'] = $args['AutomaticFailoverEnabled'] ? 'enabled' : 'disabled';
            }

            if (isset($args['MultiAZEnabled'])) {
                $this->world['multiAz'] = $args['MultiAZEnabled'] ? 'enabled' : 'disabled';
            }

            if (isset($args['PrimaryClusterId'])) {
                foreach ($this->world['members'] as $id => $member) {
                    $this->world['members'][$id]['role'] = $id === $args['PrimaryClusterId'] ? 'primary' : 'replica';
                }
            }

            return [];
        }

        protected function updateActions(array $args): array
        {
            $actions = [];

            foreach ($this->world['updates'] as $name => [$released, $status]) {
                if (isset($args['ServiceUpdateName']) && $args['ServiceUpdateName'] !== $name) {
                    continue;
                }

                if (isset($args['UpdateActionStatus']) && ! in_array($status, $args['UpdateActionStatus'], true)) {
                    continue;
                }

                $actions[] = [
                    'ReplicationGroupId' => CACHE_GROUP,
                    'ServiceUpdateName' => $name,
                    'ServiceUpdateReleaseDate' => $released,
                    'ServiceUpdateRecommendedApplyByDate' => Carbon::parse($released)->addMonth()->toIso8601String(),
                    'ServiceUpdateSeverity' => 'important',
                    'ServiceUpdateType' => 'security-update',
                    'UpdateActionStatus' => $status,
                    'NodeGroupUpdateStatus' => [['NodeGroupId' => '0001', 'NodeGroupMemberUpdateStatus' => array_map(
                        fn (string $id): array => ['CacheClusterId' => $id, 'NodeUpdateStatus' => $status],
                        array_keys($this->world['members']),
                    )]],
                ];

                // Polling advances an applied update one status at a time.
                if ($status !== 'not-applied' && isset($args['ServiceUpdateName']) && $this->world['updates'][$name][2] !== []) {
                    $this->world['updates'][$name][1] = array_shift($this->world['updates'][$name][2]);
                }
            }

            return $actions;
        }

        protected function apply(array $args): array
        {
            $name = $args['ServiceUpdateName'];
            $this->world['updates'][$name][1] = array_shift($this->world['updates'][$name][2]) ?? 'complete';

            // Patching the primary fails over to the already-patched replica.
            if ($this->world['failover'] === 'enabled' && ($this->world['members'][CACHE_GROUP . '-001']['role'] ?? null) === 'primary') {
                $this->modify(['PrimaryClusterId' => CACHE_GROUP . '-002']);
            }

            return ['ProcessedUpdateActions' => [['ServiceUpdateName' => $name]], 'UnprocessedUpdateActions' => []];
        }

        protected function removeReplicas(array $args): array
        {
            foreach ($args['ReplicasToRemove'] as $id) {
                unset($this->world['members'][$id]);
            }

            return [];
        }
    };

    Helpers::app()->instance('elastiCache', new ElastiCacheClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $handler,
    ]));
}

/**
 * @return array{0: int, 1: string}
 */
function runCacheUpdate(array $options = ['--force' => true]): array
{
    Prompt::interactive(false);
    Prompt::setOutput($prompts = new BufferedOutput());

    $command = new CacheUpdateCommand();
    $command->input = new ArrayInput(['environment' => 'testing', '--no-progress' => true, ...$options], $command->getDefinition());
    $command->output = new BufferedOutput();

    return [$command->handle(), $prompts->fetch()];
}

/**
 * The mutating calls in order, each reduced to what identifies it.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 * @return array<int, string>
 */
function cacheMutations(array $captured): array
{
    return collect($captured)
        ->filter(fn (array $call): bool => in_array($call['name'], CACHE_MUTATIONS, true))
        ->map(fn (array $call): string => match ($call['name']) {
            'ModifyReplicationGroup' => isset($call['args']['PrimaryClusterId'])
                ? 'promote ' . $call['args']['PrimaryClusterId']
                : 'failover ' . ($call['args']['AutomaticFailoverEnabled'] ? 'on' : 'off'),
            'BatchApplyUpdateAction' => 'apply ' . $call['args']['ServiceUpdateName'],
            'DecreaseReplicaCount' => 'remove ' . implode(',', $call['args']['ReplicasToRemove']),
            default => 'add replica',
        })
        ->values()
        ->all();
}

it('is named cache:update, registered, and runs at the admin tier', function (): void {
    expect((new CacheUpdateCommand())->getName())->toBe('cache:update')
        ->and((new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'])->toContain(CacheUpdateCommand::class)
        ->and(new CacheUpdateCommand())->toBeInstanceOf(AdminCommand::class);
});

it('patches through a temporary replica and returns the cache to one node', function (): void {
    $world = cacheWorld(['updates' => [
        'elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z'),
        'elasticache-20260301-001' => cacheUpdate('2026-03-01T00:00:00Z'),
    ]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit] = runCacheUpdate();

    expect($exit)->toBe(0)
        ->and(cacheMutations($captured))->toBe([
            'add replica',
            'failover on',
            // Oldest release first.
            'apply elasticache-20260301-001',
            'apply elasticache-20260901-001',
            'promote yolo-testing-cache-001',
            'failover off',
            'remove yolo-testing-cache-002',
        ])
        ->and(array_keys($world['members']))->toBe(['yolo-testing-cache-001'])
        ->and($world['failover'])->toBe('disabled');

    $add = collect($captured)->firstWhere('name', 'IncreaseReplicaCount')['args'];
    expect($add['ReplicaConfiguration'])->toBe([[
        'NodeGroupId' => '0001',
        'NewReplicaCount' => 1,
        // The primary's zone first, then another the subnet group spans.
        'PreferredAvailabilityZones' => ['ap-southeast-2a', 'ap-southeast-2b'],
    ]])->and($add['ApplyImmediately'])->toBeTrue();

    $enable = collect($captured)->firstWhere(fn (array $call): bool => $call['name'] === 'ModifyReplicationGroup' && ($call['args']['AutomaticFailoverEnabled'] ?? false) === true)['args'];
    expect($enable['MultiAZEnabled'])->toBeTrue();

    // The group, never a node, is the update target.
    expect(collect($captured)->firstWhere('name', 'BatchApplyUpdateAction')['args']['ReplicationGroupIds'])->toBe([CACHE_GROUP]);
});

it('waits on the new node itself, not the group, before turning failover on', function (): void {
    $world = cacheWorld(['updates' => ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z')]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    runCacheUpdate();

    $names = array_column($captured, 'name');
    $nodeWait = array_search('DescribeCacheClusters', array_slice($names, (int) array_search('IncreaseReplicaCount', $names, true)), true);

    expect($nodeWait)->not->toBeFalse()
        ->and(collect($captured)->last(fn (array $call): bool => $call['name'] === 'DescribeCacheClusters')['args']['CacheClusterId'])->toBe('yolo-testing-cache-002');
});

it('exits without touching the cluster when nothing is pending', function (): void {
    $world = cacheWorld(['updates' => ['elasticache-20260101-001' => cacheUpdate('2026-01-01T00:00:00Z', 'complete')]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('up to date')
        ->and(cacheMutations($captured))->toBe([]);
});

it('refuses a cluster that is not available', function (): void {
    $world = cacheWorld(['status' => 'modifying', 'updates' => ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z')]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('is modifying')
        ->and(cacheMutations($captured))->toBe([]);
});

it('refuses a topology it did not create', function (array $overrides, string $message): void {
    $world = cacheWorld([...$overrides, 'updates' => ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z')]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(1)
        ->and($output)->toContain($message)
        ->and(cacheMutations($captured))->toBe([]);
})->with([
    'failover on with one node' => [['failover' => 'enabled'], 'failover or Multi-AZ on'],
    'three members' => [['members' => [
        CACHE_GROUP . '-001' => ['role' => 'primary', 'zone' => 'ap-southeast-2a'],
        CACHE_GROUP . '-002' => ['role' => 'replica', 'zone' => 'ap-southeast-2b'],
        CACHE_GROUP . '-003' => ['role' => 'replica', 'zone' => 'ap-southeast-2c'],
    ]], 'has 3 members'],
    'original node missing' => [['members' => [CACHE_GROUP . '-002' => ['role' => 'primary', 'zone' => 'ap-southeast-2b']]], 'has no yolo-testing-cache-001 member'],
]);

it('changes nothing when the confirmation is declined', function (): void {
    $world = cacheWorld(['updates' => ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z')]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    // Non-interactive confirm falls back to its default, which is no.
    [$exit, $output] = runCacheUpdate([]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('nothing was changed')
        ->and(cacheMutations($captured))->toBe([]);
});

it('resumes mid-run from the cluster state, waiting on a started update instead of re-applying it', function (): void {
    // A run died after the first update completed and the second was applied:
    // the replica is primary and failover is still on.
    $world = cacheWorld([
        'members' => [
            CACHE_GROUP . '-001' => ['role' => 'replica', 'zone' => 'ap-southeast-2a'],
            CACHE_GROUP . '-002' => ['role' => 'primary', 'zone' => 'ap-southeast-2b'],
        ],
        'failover' => 'enabled',
        'multiAz' => 'enabled',
        'updates' => [
            'elasticache-20260301-001' => cacheUpdate('2026-03-01T00:00:00Z', 'complete'),
            'elasticache-20260601-001' => cacheUpdate('2026-06-01T00:00:00Z', 'waiting-to-start', ['complete']),
            'elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z'),
        ],
    ]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Resuming an earlier run')
        ->and(cacheMutations($captured))->toBe([
            'apply elasticache-20260901-001',
            'promote yolo-testing-cache-001',
            'failover off',
            'remove yolo-testing-cache-002',
        ]);
});

it('finishes a teardown an earlier run left behind even with nothing pending', function (): void {
    $world = cacheWorld([
        'members' => [
            CACHE_GROUP . '-001' => ['role' => 'primary', 'zone' => 'ap-southeast-2a'],
            CACHE_GROUP . '-002' => ['role' => 'replica', 'zone' => 'ap-southeast-2b'],
        ],
        'updates' => ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z', 'complete')],
    ]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('only tears the temporary replica down')
        // Failover stays off: nothing left to patch, so it is never turned on.
        ->and(cacheMutations($captured))->toBe(['remove yolo-testing-cache-002']);
});

it('stops on a halted update and leaves the replica in place', function (): void {
    $world = cacheWorld(['updates' => [
        'elasticache-20260301-001' => cacheUpdate('2026-03-01T00:00:00Z', progression: ['waiting-to-start', 'stopped']),
        'elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z'),
    ]]);
    $captured = [];
    bindCacheWorld($world, $captured);

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('elasticache-20260301-001 is stopped')
        ->and($output)->toContain('resumes')
        ->and(cacheMutations($captured))->toBe([
            'add replica',
            'failover on',
            'apply elasticache-20260301-001',
        ])
        ->and($world['members'])->toHaveKey(CACHE_GROUP . '-002');
});

it('stops when AWS refuses to apply an update', function (): void {
    $world = cacheWorld([
        'members' => [
            CACHE_GROUP . '-001' => ['role' => 'primary', 'zone' => 'ap-southeast-2a'],
            CACHE_GROUP . '-002' => ['role' => 'replica', 'zone' => 'ap-southeast-2b'],
        ],
        'failover' => 'enabled',
        'multiAz' => 'enabled',
    ]);
    $captured = [];
    bindCacheWorld($world, $captured);

    // Swap in a refusal for BatchApplyUpdateAction only.
    $world['updates'] = ['elasticache-20260901-001' => cacheUpdate('2026-09-01T00:00:00Z')];
    $client = Helpers::app('elastiCache');
    $client->getHandlerList()->appendSign(fn (callable $handler): Closure => fn (CommandInterface $cmd, $request = null) => $cmd->getName() === 'BatchApplyUpdateAction'
        ? Create::promiseFor(new Result(['UnprocessedUpdateActions' => [['ErrorType' => 'InvalidParameterValue', 'ErrorMessage' => 'The update is not applicable']]]))
        : $handler($cmd, $request));

    [$exit, $output] = runCacheUpdate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The update is not applicable')
        ->and(cacheMutations($captured))->not->toContain('remove yolo-testing-cache-002');
});

it('flags updates past their recommended apply-by date', function (): void {
    Carbon::setTestNow('2026-10-01');

    $rows = CacheUpdateCommand::pendingRows([
        ['ServiceUpdateName' => 'old', 'ServiceUpdateType' => 'security-update', 'ServiceUpdateSeverity' => 'critical', 'ServiceUpdateReleaseDate' => '2026-03-01T00:00:00Z', 'ServiceUpdateRecommendedApplyByDate' => '2026-04-01T00:00:00Z', 'UpdateActionStatus' => 'not-applied'],
        ['ServiceUpdateName' => 'new', 'ServiceUpdateType' => 'security-update', 'ServiceUpdateSeverity' => 'low', 'ServiceUpdateReleaseDate' => '2026-09-20T00:00:00Z', 'ServiceUpdateRecommendedApplyByDate' => '2026-11-20T00:00:00Z', 'UpdateActionStatus' => 'not-applied'],
    ]);

    expect($rows[0])->toBe(['old', 'security', 'critical', '2026-03-01', '<fg=red>2026-04-01 (overdue)</>', 'not-applied'])
        ->and($rows[1][4])->toBe('2026-11-20');
});

it('identifies the temporary replica as any member besides the original node', function (): void {
    $topology = new CacheTopology(['MemberClusters' => [CACHE_GROUP . '-001', CACHE_GROUP . '-002']]);

    expect($topology->temporaryReplica())->toBe(CACHE_GROUP . '-002')
        ->and($topology->isBaseline())->toBeFalse()
        ->and((new CacheTopology(['MemberClusters' => [CACHE_GROUP . '-001']]))->isBaseline())->toBeTrue();
});
