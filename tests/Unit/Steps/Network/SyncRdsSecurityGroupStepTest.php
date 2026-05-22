<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Ec2\Ec2Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Network\SyncRdsSecurityGroupStep;

/**
 * Bind a mock EC2 client with command-routed responses. A command's value may be
 * a single Result (repeated for every call) or an array of Results used as a
 * queue (the last entry repeats once exhausted). Calls are captured by reference.
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockEc2Client(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        /** @var array<string, int> */
        private array $cursors = [];

        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $name = $cmd->getName();
            $this->captured[] = ['name' => $name, 'args' => $cmd->toArray()];

            $entry = $this->byCommand[$name] ?? new Result();

            if (is_array($entry)) {
                $index = min($this->cursors[$name] ?? 0, count($entry) - 1);
                $this->cursors[$name] = $index + 1;
                $entry = $entry[$index];
            }

            return Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('ec2', new Ec2Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function describeRdsAndTaskGroups(): Result
{
    return new Result([
        'SecurityGroups' => [
            ['GroupName' => 'yolo-testing-rds-security-group', 'GroupId' => 'sg-rds123'],
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task456'],
        ],
    ]);
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

// This test must run first: it relies on AwsResources::rdsSecurityGroup() not yet
// being memoised, so the absent-SG lookup throws and the create branch runs.
it('creates the RDS security group and adds the task-SG ingress rule when absent', function () {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),   // first lookup → not found → create
            describeRdsAndTaskGroups(),             // re-lookup after create (repeats)
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-rds123']),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncRdsSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateSecurityGroup');
    expect($names)->toContain('AuthorizeSecurityGroupIngress');
    expect($names)->not->toContain('RevokeSecurityGroupIngress');
});

it('additively authorises 3306 from the task security group on an existing RDS SG', function () {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeRdsAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncRdsSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    expect($authorise)->not->toBeNull();

    $permission = $authorise['args']['IpPermissions'][0];
    expect($permission['FromPort'])->toBe(3306);
    expect($permission['ToPort'])->toBe(3306);
    expect($permission['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-task456');
    expect($authorise['args']['GroupId'])->toBe('sg-rds123');
    expect($authorise['args']['TagSpecifications'][0]['Tags'][0]['Value'])->toBe('rds-task-ingress');

    // Purely additive — it must never revoke an existing rule.
    expect(array_column($captured, 'name'))->not->toContain('RevokeSecurityGroupIngress');
});

it('does not authorise again when the tagged task-SG rule already exists', function () {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeRdsAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            ['SecurityGroupRuleId' => 'sgr-existing'],
        ]]),
    ], $captured);

    (new SyncRdsSecurityGroupStep())([]);

    expect(array_column($captured, 'name'))
        ->not->toContain('AuthorizeSecurityGroupIngress')
        ->not->toContain('RevokeSecurityGroupIngress');
});

it('treats a manifest-specified RDS security group as custom-managed', function () {
    writeManifest([
        'aws' => [
            'account-id' => '111111111111',
            'region' => 'ap-southeast-2',
            'rds' => ['security-group' => 'yolo-testing-rds-security-group'],
        ],
    ]);

    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeRdsAndTaskGroups(),
    ], $captured);

    expect((new SyncRdsSecurityGroupStep())([]))->toBe(StepResult::CUSTOM_MANAGED);
    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('does not authorise during a dry-run', function () {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeRdsAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    (new SyncRdsSecurityGroupStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});
