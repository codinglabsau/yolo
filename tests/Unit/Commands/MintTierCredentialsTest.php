<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sts\StsClient;
use Codinglabs\Yolo\Aws;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Aws\Credentials\Credentials;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Commands\RunCommand;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\AuditCommand;
use Codinglabs\Yolo\Commands\BuildCommand;
use Codinglabs\Yolo\Commands\ScaleCommand;
use Codinglabs\Yolo\Commands\DeployCommand;
use Codinglabs\Yolo\Commands\StatusCommand;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Commands\AuditAppCommand;
use Codinglabs\Yolo\Commands\StatusAppCommand;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Commands\StatusLogsCommand;
use Codinglabs\Yolo\Commands\StatusAlarmsCommand;
use Codinglabs\Yolo\Commands\StatusBudgetCommand;
use Codinglabs\Yolo\Commands\StatusEventsCommand;
use Codinglabs\Yolo\Commands\SyncEnvironmentCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Commands\AuditEnvironmentCommand;
use Codinglabs\Yolo\Commands\StatusEnvironmentCommand;

/**
 * Bind an STS client whose AssumeRole call records its args and then resolves to
 * the supplied Result (a Credentials payload) or throws the supplied exception
 * (the fail-open path). The callable form is the SDK's documented mock hook.
 *
 * @param  array<int, array<string, mixed>>  $captured
 */
function bindAssumeRoleStsClient(array &$captured, Result|Throwable $response): void
{
    $mock = new MockHandler();
    $mock->append(function ($command, $request) use (&$captured, $response): Result|Throwable {
        $captured[] = ['name' => $command->getName(), 'args' => $command->toArray()];

        return $response;
    });

    Helpers::app()->instance('sts', new StsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function assumeRoleResult(): Result
{
    return new Result([
        'Credentials' => [
            'AccessKeyId' => 'ASIA-OBSERVER',
            'SecretAccessKey' => 'observer-secret',
            'SessionToken' => 'observer-session-token',
            'Expiration' => '2026-01-01T00:00:00Z',
        ],
    ]);
}

function mint(Command $command): void
{
    (new ReflectionMethod($command, 'mintTierCredentials'))->invoke($command);
}

function tierOf(Command $command): ?Iam
{
    return (new ReflectionMethod($command, 'awsTier'))->invoke($command);
}

beforeEach(function (): void {
    // The container is a process singleton with no global reset, so a minted
    // binding from one case would otherwise leak into the next — forget it.
    Helpers::app()->forgetInstance('yoloAssumedCredentials');

    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('returns the assumed-role Credentials and sends the role ARN + session name', function (): void {
    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    $credentials = Aws::assumeRole('arn:aws:iam::111111111111:role/yolo-testing-observer-role', 'yolo-observer-role');

    expect($credentials)->toMatchArray([
        'AccessKeyId' => 'ASIA-OBSERVER',
        'SecretAccessKey' => 'observer-secret',
        'SessionToken' => 'observer-session-token',
    ]);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['name'])->toBe('AssumeRole')
        ->and($captured[0]['args']['RoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-observer-role')
        ->and($captured[0]['args']['RoleSessionName'])->toBe('yolo-observer-role');
});

it('marks every read command as a ReadOnlyCommand that runs under the Observer tier', function (Command $command): void {
    expect($command)->toBeInstanceOf(ReadOnlyCommand::class);
    expect(tierOf($command))->toBe(Iam::OBSERVER_ROLE);
})->with([
    'status' => fn (): Command => new StatusCommand(),
    'status:app' => fn (): Command => new StatusAppCommand(),
    'status:environment' => fn (): Command => new StatusEnvironmentCommand(),
    'status:logs' => fn (): Command => new StatusLogsCommand(),
    'status:events' => fn (): Command => new StatusEventsCommand(),
    'status:alarms' => fn (): Command => new StatusAlarmsCommand(),
    'status:budget' => fn (): Command => new StatusBudgetCommand(),
    'audit' => fn (): Command => new AuditCommand(),
    'audit:app' => fn (): Command => new AuditAppCommand(),
    'audit:environment' => fn (): Command => new AuditEnvironmentCommand(),
]);

it('runs the deploy lifecycle under the Deployer tier', function (Command $command): void {
    expect($command)->toBeInstanceOf(DeployerCommand::class);
    expect($command)->not->toBeInstanceOf(ReadOnlyCommand::class);
    expect(tierOf($command))->toBe(Iam::DEPLOYER_ROLE);
})->with([
    'deploy' => fn (): Command => new DeployCommand(),
    'build' => fn (): Command => new BuildCommand(),
    'run' => fn (): Command => new RunCommand(),
]);

it('runs the provisioning commands under the Admin tier', function (Command $command): void {
    expect($command)->toBeInstanceOf(AdminCommand::class);
    expect($command)->not->toBeInstanceOf(ReadOnlyCommand::class);
    expect($command)->not->toBeInstanceOf(DeployerCommand::class);
    expect(tierOf($command))->toBe(Iam::ADMIN_ROLE);
})->with([
    'sync' => fn (): Command => new SyncCommand(),
    'sync:environment' => fn (): Command => new SyncEnvironmentCommand(),
    'sync:app' => fn (): Command => new SyncAppCommand(),
    'scale' => fn (): Command => new ScaleCommand(),
]);

it('is a no-op for an un-tiered command — never assumes a role, never overrides credentials', function (): void {
    // An un-tiered command (no tier marker) — awsTier() returns null.
    $untiered = new class() extends Command
    {
        protected function configure(): void
        {
            $this->setName('untiered-fixture');
        }

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    expect(tierOf($untiered))->toBeNull();

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint($untiered);

    expect($captured)->toBeEmpty();
    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeFalse();
});

it('self-activates: a read command does not mint while the Observer role is unprovisioned', function (): void {
    // No role in the account yet (the brand-new role is provisioned nowhere) —
    // ObserverRole::exists() is false, so the mint is skipped entirely.
    bindMockIamClient([]);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new StatusCommand());

    expect($captured)->toBeEmpty();
    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeFalse();
});

it('mints the Observer credentials once the role is provisioned', function (): void {
    bindMockIamClient(['yolo-testing-observer-role' => 'arn:aws:iam::111111111111:role/yolo-testing-observer-role']);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new StatusCommand());

    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeTrue();

    $credentials = Helpers::app('yoloAssumedCredentials');
    expect($credentials)->toBeInstanceOf(Credentials::class)
        ->and($credentials->getAccessKeyId())->toBe('ASIA-OBSERVER')
        ->and($credentials->getSecretKey())->toBe('observer-secret')
        ->and($credentials->getSecurityToken())->toBe('observer-session-token');

    // It assumed exactly the env's observer role, named for the tier.
    expect($captured)->toHaveCount(1)
        ->and($captured[0]['args']['RoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-observer-role')
        ->and($captured[0]['args']['RoleSessionName'])->toBe('yolo-observer-role');
});

it('mints the Deployer credentials for a deploy once the app deployer role is provisioned', function (): void {
    bindMockIamClient(['yolo-testing-my-app-deployer' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer']);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new DeployCommand());

    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeTrue();

    // It assumed exactly this app's deployer role, named for the tier.
    expect($captured)->toHaveCount(1)
        ->and($captured[0]['args']['RoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer')
        ->and($captured[0]['args']['RoleSessionName'])->toBe('yolo-deployer');
});

it('self-activates for the Deployer tier too: no mint until the deployer role exists', function (): void {
    bindMockIamClient([]);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new DeployCommand());

    expect($captured)->toBeEmpty();
    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeFalse();
});

it('mints the Admin credentials for a sync once the env admin role is provisioned', function (): void {
    bindMockIamClient(['yolo-testing-admin-role' => 'arn:aws:iam::111111111111:role/yolo-testing-admin-role']);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new SyncEnvironmentCommand());

    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeTrue();

    // It assumed exactly the env's admin role, named for the tier.
    expect($captured)->toHaveCount(1)
        ->and($captured[0]['args']['RoleArn'])->toBe('arn:aws:iam::111111111111:role/yolo-testing-admin-role')
        ->and($captured[0]['args']['RoleSessionName'])->toBe('yolo-admin-role');
});

it('self-activates for the Admin tier too: the first sync runs on the profile before the role exists', function (): void {
    bindMockIamClient([]);

    $captured = [];
    bindAssumeRoleStsClient($captured, assumeRoleResult());

    mint(new SyncEnvironmentCommand());

    expect($captured)->toBeEmpty();
    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeFalse();
});

it('fails open: an AssumeRole failure leaves YOLO on the profile credentials and warns', function (): void {
    bindMockIamClient(['yolo-testing-observer-role' => 'arn:aws:iam::111111111111:role/yolo-testing-observer-role']);

    $captured = [];
    bindAssumeRoleStsClient($captured, new RuntimeException('access denied assuming role'));

    // No exception escapes — the run continues.
    mint(new StatusCommand());

    expect(Helpers::app()->bound('yoloAssumedCredentials'))->toBeFalse();

    expect(test()->promptOutput->fetch())
        ->toContain('observer-role')
        ->toContain('continuing on the profile credentials');
});
