<?php

use Aws\Result;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Credentials\Totp;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\AgentRotateCommand;
use Codinglabs\Yolo\Credentials\OnePasswordVault;
use Symfony\Component\Console\Output\BufferedOutput;

const AGENT_SEED = 'JBSWY3DPEHPK3PXP';
const ROTATE_NOW = 1_800_000_000;

/**
 * A vault that records what it was asked to do instead of shelling out to `op`.
 */
function fakeVault(?array $existing = null, ?Throwable $createFails = null): OnePasswordVault
{
    $vault = new class($existing, $createFails) extends OnePasswordVault
    {
        /** @var array<int, array{0: string, 1: mixed}> */
        public array $calls = [];

        public function __construct(protected ?array $existing, protected ?Throwable $createFails) {}

        public function item(string $title, string $vault): ?array
        {
            $this->calls[] = ['item', $title];

            return $this->existing;
        }

        public function create(string $vault, array $item): string
        {
            $this->calls[] = ['create', $item];

            if ($this->createFails instanceof Throwable) {
                throw $this->createFails;
            }

            return 'new-item-id';
        }

        public function archive(string $id, string $vault): void
        {
            $this->calls[] = ['archive', $id];
        }
    };

    Helpers::app()->instance(OnePasswordVault::class, $vault);

    return $vault;
}

function rotate(array $options = []): int
{
    $command = new class() extends AgentRotateCommand
    {
        protected function onePasswordCliInstalled(): bool
        {
            return true;
        }

        protected function now(): int
        {
            return ROTATE_NOW;
        }
    };

    $command->input = new ArrayInput(['environment' => 'testing', ...$options], $command->getDefinition());

    return $command->handle();
}

/**
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 * @return array<int, string>
 */
function iamWrites(array $captured): array
{
    return collect($captured)
        ->pluck('name')
        ->reject(fn (string $name): bool => str_starts_with($name, 'Get') || str_starts_with($name, 'List'))
        ->values()
        ->all();
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

afterEach(function (): void {
    Helpers::app()->forgetInstance(OnePasswordVault::class);
});

it('enrols MFA, cuts a key and writes both to the vault on the first run', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => new Result(['User' => ['UserName' => 'yolo-testing-agent-user']]),
        'ListMFADevices' => new Result(['MFADevices' => []]),
        'ListAccessKeys' => new Result(['AccessKeyMetadata' => []]),
        'CreateVirtualMFADevice' => new Result(['VirtualMFADevice' => [
            'SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user',
            'Base32StringSeed' => AGENT_SEED,
        ]]),
        'CreateAccessKey' => new Result(['AccessKey' => ['AccessKeyId' => 'AKIANEWEXAMPLE000002', 'SecretAccessKey' => 'new-secret']]),
    ], $captured);
    $vault = fakeVault();

    expect(rotate())->toBe(AgentRotateCommand::SUCCESS);

    expect(iamWrites($captured))->toBe(['CreateVirtualMFADevice', 'EnableMFADevice', 'CreateAccessKey']);

    $enable = collect($captured)->firstWhere('name', 'EnableMFADevice')['args'];
    expect($enable['AuthenticationCode1'])->toBe(Totp::code(AGENT_SEED, ROTATE_NOW - 30))
        ->and($enable['AuthenticationCode2'])->toBe(Totp::code(AGENT_SEED, ROTATE_NOW));

    $created = collect($vault->calls)->firstWhere(0, 'create')[1];
    expect($created['title'])->toBe('YOLO 111111111111 testing agent');

    $fields = collect($created['fields'])->keyBy('label');
    expect($fields['aws_access_key_id']['value'])->toBe('AKIANEWEXAMPLE000002')
        ->and($fields['aws_secret_access_key']['value'])->toBe('new-secret')
        ->and($fields['aws_secret_access_key']['type'])->toBe('CONCEALED')
        ->and($fields['one-time password']['type'])->toBe('OTP')
        ->and(Totp::seedFrom($fields['one-time password']['value']))->toBe(AGENT_SEED);

    // Nothing secret reaches the terminal.
    expect(test()->promptOutput->fetch())->not->toContain('new-secret')->not->toContain(AGENT_SEED);
});

it('keeps the enrolled device, carries its seed over, and deletes the old key only after the write', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => new Result(['User' => ['UserName' => 'yolo-testing-agent-user']]),
        'ListMFADevices' => new Result(['MFADevices' => [['SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user']]]),
        'ListAccessKeys' => new Result(['AccessKeyMetadata' => [['AccessKeyId' => 'AKIAOLDEXAMPLE000001', 'CreateDate' => '2026-01-01T00:00:00Z']]]),
        'CreateAccessKey' => new Result(['AccessKey' => ['AccessKeyId' => 'AKIANEWEXAMPLE000002', 'SecretAccessKey' => 'new-secret']]),
    ], $captured);
    $vault = fakeVault(['id' => 'old-item-id', 'fields' => [
        ['type' => 'OTP', 'label' => 'one-time password', 'value' => Totp::uri(AGENT_SEED, 'yolo-testing-agent-user')],
    ]]);

    expect(rotate())->toBe(AgentRotateCommand::SUCCESS);

    expect(iamWrites($captured))->toBe(['CreateAccessKey', 'DeleteAccessKey'])
        ->and(collect($captured)->firstWhere('name', 'DeleteAccessKey')['args']['AccessKeyId'])->toBe('AKIAOLDEXAMPLE000001');

    expect(collect($vault->calls)->pluck(0)->all())->toBe(['item', 'create', 'archive']);

    $fields = collect(collect($vault->calls)->firstWhere(0, 'create')[1]['fields'])->keyBy('label');
    expect(Totp::seedFrom($fields['one-time password']['value']))->toBe(AGENT_SEED);
});

it('refuses to cut a key when the device seed is lost, unless told to reset MFA', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => new Result(['User' => ['UserName' => 'yolo-testing-agent-user']]),
        'ListMFADevices' => new Result(['MFADevices' => [['SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user']]]),
    ], $captured);
    fakeVault();

    expect(rotate())->toBe(AgentRotateCommand::FAILURE);
    expect(iamWrites($captured))->toBe([]);
    expect(test()->promptOutput->fetch())->toContain('--reset-mfa');
});

it('replaces the device with --reset-mfa', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => new Result(['User' => ['UserName' => 'yolo-testing-agent-user']]),
        'ListMFADevices' => new Result(['MFADevices' => [['SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user']]]),
        'ListAccessKeys' => new Result(['AccessKeyMetadata' => []]),
        'CreateVirtualMFADevice' => new Result(['VirtualMFADevice' => [
            'SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user',
            'Base32StringSeed' => AGENT_SEED,
        ]]),
        'CreateAccessKey' => new Result(['AccessKey' => ['AccessKeyId' => 'AKIANEWEXAMPLE000002', 'SecretAccessKey' => 'new-secret']]),
    ], $captured);
    fakeVault();

    expect(rotate(['--reset-mfa' => true]))->toBe(AgentRotateCommand::SUCCESS);
    expect(iamWrites($captured))->toBe(['DeactivateMFADevice', 'DeleteVirtualMFADevice', 'CreateVirtualMFADevice', 'EnableMFADevice', 'CreateAccessKey']);
});

it('deletes the new key again when the vault write fails, leaving the old one in service', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => new Result(['User' => ['UserName' => 'yolo-testing-agent-user']]),
        'ListMFADevices' => new Result(['MFADevices' => [['SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user']]]),
        'ListAccessKeys' => new Result(['AccessKeyMetadata' => [['AccessKeyId' => 'AKIAOLDEXAMPLE000001', 'CreateDate' => '2026-01-01T00:00:00Z']]]),
        'CreateAccessKey' => new Result(['AccessKey' => ['AccessKeyId' => 'AKIANEWEXAMPLE000002', 'SecretAccessKey' => 'new-secret']]),
    ], $captured);
    fakeVault(['id' => 'old-item-id', 'fields' => [['type' => 'OTP', 'value' => AGENT_SEED]]], new RuntimeException('vault is read-only'));

    expect(rotate())->toBe(AgentRotateCommand::FAILURE);

    expect(iamWrites($captured))->toBe(['CreateAccessKey', 'DeleteAccessKey'])
        ->and(collect($captured)->firstWhere('name', 'DeleteAccessKey')['args']['AccessKeyId'])->toBe('AKIANEWEXAMPLE000002');
});

it('asks for an environment sync when the agent user does not exist yet', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetUser' => fn ($command): IamException => new IamException('not found', $command, ['code' => 'NoSuchEntity']),
    ], $captured);
    fakeVault();

    expect(rotate())->toBe(AgentRotateCommand::FAILURE);
    expect(test()->promptOutput->fetch())->toContain('yolo sync:environment testing');
});
