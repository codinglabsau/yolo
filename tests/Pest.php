<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\Acm\AcmClient;
use Aws\Ec2\Ec2Client;
use Aws\Ecr\EcrClient;
use Aws\Ecs\EcsClient;
use Aws\Iam\IamClient;
use Aws\Rds\RdsClient;
use Aws\Sqs\SqsClient;
use Aws\CommandInterface;
use Aws\WAFV2\WAFV2Client;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use GuzzleHttp\Psr7\Response;
use Aws\Command as AwsCommand;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\ElastiCache\ElastiCacheClient;
use Aws\EventBridge\EventBridgeClient;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Symfony\Component\Console\Input\ArrayInput;
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

// Rebind per test, not once at load, so a test that swaps the global container —
// e.g. a Testbench-based runtime test booting its own Laravel app — can't strand a
// sibling unit file batched into the same --parallel worker without 'environment'.
pest()->beforeEach(function (): void {
    Helpers::app()->instance('environment', 'testing');
    Manifest::flushHydration();

    // Running any console command in a Testbench app makes the framework set
    // Laravel Prompts' fallback flag (ConfiguresPrompts does so whenever
    // runningUnitTests()), and that static is sticky with no public unset —
    // so one Testbench artisan test would flip every later CLI test's prompts
    // onto the Symfony fallback path and break their prompt stubs. Reset it.
    $shouldFallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $shouldFallback->setValue(null, false);
})->in(__DIR__);

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

    // The env manifest, the service-claims registry and the Typesense admin
    // key memoise their AWS reads per process; every test that rewrites the
    // app manifest gets a fresh slate so a previously mocked (or unmocked)
    // read can't leak across cases.
    EnvManifest::reset();
    Lifecycle::reset();
    Typesense::reset();
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
 * Assert a sync step honours the reconciler contract that the invisible-write class
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

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
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
 * Bind a mock SQS client with command-routed responses, capturing every call.
 * Mirrors bindMockCloudWatchClient.
 *
 * @param  array<string, Result|Throwable>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockSqsClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('sqs', new SqsClient([
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
 * Bind a mock RDS client with command-routed responses, capturing every call.
 * A command's value may be a single Result/Throwable (repeated) or an array used
 * as a queue (the last entry repeats once exhausted); a Throwable is returned as
 * a rejection (e.g. a DescribeDBInstances DBInstanceNotFound). Mirrors
 * bindMockElastiCacheClient.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockRdsClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('rds', new RdsClient([
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

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
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

/**
 * Run an environment-file command's handle() directly with a bound input —
 * skipping the base execute() plumbing (auth, STS guard, manifest checks) so
 * tests exercise the command's own behaviour against mocked AWS only.
 */
function runEnvironmentFileCommand(Command $command, string $environment = 'testing'): void
{
    $command->input = new ArrayInput(
        ['environment' => $environment],
        $command->getDefinition(),
    );

    $command->handle();
}

/**
 * Bind a mock EventBridge client with command-routed responses, capturing
 * every call. A command's value may be a single Result/Throwable (repeated)
 * or an array used as a queue (the last entry repeats once exhausted);
 * Throwables resolve as rejections. Mirrors bindRoutedS3Client.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedEventBridgeClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('eventBridge', new EventBridgeClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock ECR client with command-routed responses, capturing every call.
 * A command's value may be a single Result/Throwable (repeated) or an array
 * used as a queue. Mirrors bindRoutedS3Client.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedEcrClient(array $byCommand, array &$captured): void
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

    Helpers::app()->instance('ecr', new EcrClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind the S3 + ECS world the service lifecycle reads: the env manifest body
 * (null = no manifest object), the published claim files (app => claimed
 * services), and the environment's clusters (app => has running tasks).
 * GetObject is routed by KEY — the env manifest and each claim file resolve
 * independently of read order. `bucket: false` makes every S3 read throw
 * NoSuchBucket (the greenfield plan pass). S3 calls are captured by reference.
 *
 * @param  array{manifest?: string|null, claims?: array<string, array<int, string>>, clusters?: array<string, bool>, bucket?: bool, sharedEnv?: string|null, appEnvSide?: array<string, string>}  $world
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindServiceLifecycleWorld(array $world, array &$captured): void
{
    $manifest = $world['manifest'] ?? null;
    $claims = $world['claims'] ?? [];
    $clusters = $world['clusters'] ?? [];
    $bucketExists = $world['bucket'] ?? true;

    $byKey = [];

    if ($manifest !== null) {
        $byKey['yolo-environment-' . Helpers::environment() . '.yml'] = new Result(['Body' => $manifest]);
    }

    if (($world['sharedEnv'] ?? null) !== null) {
        $byKey['.env.environment.' . Helpers::environment()] = new Result(['Body' => $world['sharedEnv']]);
    }

    // Each app's environment-side `.env` (env/.env.{app}) — the YOLO-minted
    // per-app secret channel the build merges and SyncTypesenseKeyStep reads
    // back as its idempotency marker. Routed by key alongside the claims, so a
    // present file is read fresh and an absent one falls through to NoSuchKey.
    foreach ($world['appEnvSide'] ?? [] as $app => $body) {
        $byKey["env/.env.$app"] = new Result(['Body' => $body]);
    }

    foreach ($claims as $app => $services) {
        $byKey["apps/$app.yml"] = new Result(['Body' => Yaml::dump(['name' => $app, 'services' => $services])]);
    }

    $listing = new Result([
        'Contents' => collect($claims)->keys()->map(fn (string $app): array => ['Key' => "apps/$app.yml"])->values()->all(),
        'IsTruncated' => false,
    ]);

    $mock = new class($byKey, $listing, $bucketExists, $captured) extends MockHandler
    {
        /** @param  array<string, Result>  $byKey */
        public function __construct(
            protected array $byKey,
            protected Result $listing,
            protected bool $bucketExists,
            protected array &$captured,
        ) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            if (! $this->bucketExists) {
                return Create::rejectionFor(new S3Exception('No such bucket', new AwsCommand($cmd->getName()), [
                    'code' => 'NoSuchBucket',
                    'response' => new Response(404),
                ]));
            }

            return match (true) {
                $cmd->getName() === 'ListObjectsV2' => Create::promiseFor($this->listing),
                $cmd->getName() === 'PutObject' => Create::promiseFor(new Result()),
                $cmd->getName() === 'HeadObject' && isset($this->byKey[$cmd['Key']]) => Create::promiseFor(new Result()),
                isset($this->byKey[$cmd['Key'] ?? '']) => Create::promiseFor($this->byKey[$cmd['Key']]),
                default => Create::rejectionFor(new S3Exception('Not found', new AwsCommand($cmd->getName()), [
                    'code' => 'NoSuchKey',
                    'response' => new Response(404),
                ])),
            };
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));

    $accountId = '111111111111';

    $clusterArns = collect($clusters)
        ->keys()
        ->map(fn (string $app): string => sprintf('arn:aws:ecs:ap-southeast-2:%s:cluster/yolo-%s-%s', $accountId, Helpers::environment(), $app))
        ->values()
        ->all();

    // ListTasks resolves per cluster in ListClusters order, so the running
    // flags queue in the same order the lifecycle probes them.
    $taskResults = collect($clusters)
        ->values()
        ->map(fn (bool $running): Result => new Result(['taskArns' => $running ? ['arn:aws:ecs:ap-southeast-2:111111111111:task/x'] : []]))
        ->all();

    $ecsCaptured = [];
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => $clusterArns]),
        'ListTasks' => $taskResults === [] ? new Result(['taskArns' => []]) : $taskResults,
    ], $ecsCaptured);
}

/**
 * Bind a mock ACM client that resolves listCertificates to a single summary for
 * the given domain at the given status (ISSUED by default). Shared by the steps
 * that gate on a cert being issued — the HTTPS listener and the forward/redirect
 * listener rules.
 */
function bindIssuedAcmCertificate(string $domain, string $certificateArn, string $status = 'ISSUED'): void
{
    $mock = new class($domain, $certificateArn, $status) extends MockHandler
    {
        public function __construct(
            private readonly string $domain,
            private readonly string $certificateArn,
            private readonly string $status,
        ) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor(new Result([
                'CertificateSummaryList' => [[
                    'DomainName' => $this->domain,
                    'CertificateArn' => $this->certificateArn,
                    'Status' => $this->status,
                ]],
            ]));
        }
    };

    Helpers::app()->instance('acm', new AcmClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock ACM client with no certificates — listCertificates returns an
 * empty list, so Acm::certificate() throws not-found for any domain.
 */
function bindNoAcmCertificates(): void
{
    $mock = new class() extends MockHandler
    {
        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor(new Result(['CertificateSummaryList' => []]));
        }
    };

    Helpers::app()->instance('acm', new AcmClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a mock Route 53 client whose listHostedZones returns the given zone
 * names (apex derivation lists zones and matches a domain's longest
 * label-suffix against them). Pass nothing for an account with no zones, where
 * apex derivation falls back to the domain with any leading `www.` stripped.
 *
 * @param  array<int, string>  $zoneNames
 */
function bindHostedZones(array $zoneNames = []): void
{
    $hostedZones = array_map(
        fn (string $name): array => ['Name' => rtrim($name, '.') . '.'],
        $zoneNames,
    );

    $mock = new class($hostedZones) extends MockHandler
    {
        public function __construct(private readonly array $hostedZones) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor(new Result(['HostedZones' => $this->hostedZones]));
        }
    };

    Helpers::app()->instance('route53', new Route53Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}
