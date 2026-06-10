<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\Ec2\Ec2Client;
use Aws\Ecs\EcsClient;
use Aws\Iam\IamClient;
use Aws\CommandInterface;
use Aws\WAFV2\WAFV2Client;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\ElastiCache\ElastiCacheClient;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Aws\ApplicationAutoScaling\ApplicationAutoScalingClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
|
| Set up a temporary manifest and environment so tests can use Manifest,
| Helpers::keyedResourceName(), and other static helpers without touching
| a real yolo.yml or AWS.
|
*/

$tempDir = sys_get_temp_dir() . '/yolo-test-' . (getenv('TEST_TOKEN') ?: getmypid());

@mkdir($tempDir, 0755, true);

if (! defined('BASE_PATH')) {
    define('BASE_PATH', $tempDir);
}

file_put_contents($tempDir . '/yolo.yml', Yaml::dump([
    'name' => 'my-app',
    'environments' => [
        'testing' => ['account-id' => '111111111111'],
    ],
], 10, 2));

Helpers::app()->instance('environment', 'testing');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function writeManifest(array $config, string $environment = 'testing'): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', Yaml::dump([
        'name' => 'my-app',
        'environments' => [
            $environment => $config,
        ],
    ], 10, 2));

    Helpers::app()->instance('environment', $environment);

    // The env manifest memoises its S3 read per process; every test that
    // rewrites the app manifest gets a fresh slate so a previously mocked
    // (or unmocked) read can't leak across cases.
    EnvManifest::reset();
}

/**
 * Bind a mock S3 client with command-routed responses, capturing every call.
 * A command's value may be a single Result/Throwable (repeated) or an array
 * used as a queue (the last entry repeats once exhausted); Throwables resolve
 * as rejections (e.g. a missing object/bucket). Mirrors bindMockEc2Client.
 * (bindMockS3Client in SyncS3BucketHardeningTest.php is file-local; this is
 * the Pest-wide twin for files that need an S3 mock under --parallel.)
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedS3Client(array $byCommand, array &$captured): void
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

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Assert a sync step honours the reconciler contract that the LPX-646 / #95 class
 * of bug violated: its plan-pass status and recorded changes must reflect ACTUAL
 * drift, and it must never write on the plan pass. This is the shared standard —
 * every sync step that mutates AWS should carry one of these:
 *
 *   in-sync ⇒ SYNCED, no recorded Change, and no $writeCommand on either pass
 *   drifted ⇒ WOULD_SYNC + a recorded Change on the plan (no write), $writeCommand on apply
 *
 * $makeStep returns a fresh step instance; $bindInSync / $bindDrifted bind the AWS
 * mocks for each state (each receives the &$captured call log by reference).
 */
function assertSyncStepReconciles(Closure $makeStep, Closure $bindInSync, Closure $bindDrifted, string $writeCommand): void
{
    // In sync: the plan is clean (status + changes), and neither pass writes.
    $captured = [];
    $bindInSync($captured);
    $planned = $makeStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect($planned->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain($writeCommand);

    $captured = [];
    $bindInSync($captured);
    expect($makeStep()([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain($writeCommand);

    // Drifted: the plan records the drift but writes nothing; apply writes.
    $captured = [];
    $bindDrifted($captured);
    $planned = $makeStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($planned->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain($writeCommand);

    $captured = [];
    $bindDrifted($captured);
    $makeStep()([]);
    expect(array_column($captured, 'name'))->toContain($writeCommand);
}

/**
 * Bind a mock IAM client whose ListRoles returns the supplied roles. The handler
 * repeats (no MockHandler queue), so any number of lookups — task role, execution
 * role, etc. — resolve without exhausting a queue.
 *
 * @param  array<string, string>  $roles  roleName => roleArn
 */
function bindMockIamClient(array $roles): void
{
    $result = new Result([
        'Roles' => collect($roles)
            ->map(fn (string $arn, string $name): array => ['RoleName' => $name, 'Arn' => $arn])
            ->values()
            ->all(),
        'IsTruncated' => false,
    ]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('iam', new IamClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock IAM client with command-routed responses, capturing every call.
 * A command's value may be a single Result (repeated) or an array of Results
 * used as a queue (the last entry repeats once exhausted). Mirrors the EC2 mock
 * in SyncRdsSecurityGroupStepTest.
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedIamClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('iam', new IamClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock CloudFront client whose ListDistributions returns the supplied
 * distribution items. Repeating handler — every call resolves the same list.
 *
 * @param  array<int, array<string, mixed>>  $distributions
 */
function bindMockCloudFrontClient(array $distributions): void
{
    $result = new Result([
        'DistributionList' => [
            'Items' => $distributions,
            'IsTruncated' => false,
        ],
    ]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('cloudFront', new CloudFrontClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

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

/**
 * Bind a mock CloudWatch client with command-routed responses, capturing every
 * call. A command's value is a single Result (repeated for every call), or a
 * Throwable (returned as a rejection, e.g. a GetDashboard not-found) repeated
 * the same way. Mirrors bindMockEc2Client.
 *
 * @param  array<string, Result|Throwable>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockCloudWatchClient(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            $entry = $this->byCommand[$cmd->getName()] ?? new Result();

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('cloudWatch', new CloudWatchClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock ElastiCache client with command-routed responses. A command's
 * value may be a single Result (repeated) or an array of Results used as a queue
 * (the last entry repeats once exhausted). Calls are captured by reference.
 * Mirrors bindMockEc2Client.
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockElastiCacheClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('elastiCache', new ElastiCacheClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock Application Auto Scaling client with command-routed responses,
 * capturing every call. Mirrors bindMockEc2Client.
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockApplicationAutoScalingClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('applicationAutoScaling', new ApplicationAutoScalingClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock ECS client with command-routed responses, capturing every call.
 * Mirrors bindMockEc2Client. (Named bindRouted* to avoid colliding with the
 * MockHandler-based bindMockEcsClient in tests/Unit/Aws/EcsTest.php.)
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedEcsClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('ecs', new EcsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock ELBv2 client with command-routed responses, capturing every call.
 * Mirrors bindMockEc2Client. (bindRecordingElbV2Client in TargetGroupTest.php is
 * target-group specific; this one routes any command — e.g. DescribeLoadBalancers
 * + DescribeTargetGroups together for ResourceLabel resolution.)
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedElbV2Client(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('elasticLoadBalancingV2', new ElasticLoadBalancingV2Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock Tagging API client under the given container key — 'resourceGroupsTaggingApi'
 * for the regional pass or 'resourceGroupsTaggingApiGlobal' for the us-east-1 pass. GetResources
 * returns the supplied pages in order, the last repeating once the queue is exhausted, so a
 * single-page array covers the common case and a multi-page array exercises PaginationToken
 * following.
 *
 * @param  array<int, Result>  $pages
 */
function bindMockResourceGroupsTaggingApiClient(string $binding, array $pages): void
{
    $mock = new class($pages) extends MockHandler
    {
        private int $cursor = 0;

        /** @param  array<int, Result>  $pages */
        public function __construct(protected array $pages) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $index = min($this->cursor, count($this->pages) - 1);
            $this->cursor++;

            return Create::promiseFor($this->pages[$index]);
        }
    };

    Helpers::app()->instance($binding, new ResourceGroupsTaggingAPIClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock WAFv2 client with command-routed responses, capturing every call.
 * A command's value may be a single Result (repeated) or an array of Results used
 * as a queue (the last entry repeats once exhausted). Mirrors bindMockEc2Client.
 *
 * @param  array<string, Result|array<int, Result>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedWafV2Client(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('wafV2', new WAFV2Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Shared WAF fixtures — defined here (not in a single test file) so every Pest
 * worker has them under `--parallel`, where each test file runs in isolation.
 */
function wafIpSetsResult(): Result
{
    return new Result(['IPSets' => [
        ['Name' => 'yolo-testing-waf-allow', 'Id' => 'allow-id', 'LockToken' => 'lt-allow', 'ARN' => 'arn:aws:wafv2:ap-southeast-2:111:regional/ipset/yolo-testing-waf-allow/allow-id'],
        ['Name' => 'yolo-testing-waf-block', 'Id' => 'block-id', 'LockToken' => 'lt-block', 'ARN' => 'arn:aws:wafv2:ap-southeast-2:111:regional/ipset/yolo-testing-waf-block/block-id'],
    ]]);
}

function wafWebAclsResult(): Result
{
    return new Result(['WebACLs' => [
        ['Name' => 'yolo-testing-waf', 'Id' => 'acl-id', 'LockToken' => 'lt-acl', 'ARN' => 'arn:aws:wafv2:ap-southeast-2:111:regional/webacl/yolo-testing-waf/acl-id'],
    ]]);
}

function wafWebAclTagsResult(): Result
{
    return new Result(['TagInfoForResource' => ['TagList' => [
        ['Key' => 'Name', 'Value' => 'yolo-testing-waf'],
        ['Key' => 'yolo:scope', 'Value' => 'env'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ]]]);
}

/**
 * A live GetWebACL response wrapping the given rules + default action.
 *
 * @param  array<int, array<string, mixed>>  $rules
 * @param  array<string, mixed>  $defaultAction
 */
function liveWebAclResult(array $rules, array $defaultAction = ['Allow' => []]): Result
{
    return new Result([
        'WebACL' => ['Rules' => $rules, 'DefaultAction' => $defaultAction],
        'LockToken' => 'lt-acl',
    ]);
}

/**
 * The WebAcl resource's own desired rules, resolved against the mocked IP sets.
 *
 * @return array<int, array<string, mixed>>
 */
function desiredWafRules(): array
{
    $captured = [];
    bindRoutedWafV2Client(['ListIPSets' => wafIpSetsResult()], $captured);

    return (new WebAcl())->desiredRules();
}
