<?php

use Aws\Result;
use Aws\Command;
use Laravel\Prompts\Prompt;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\DeployCheck;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The older-CLI refusal (ensureCliMayApply) — the plan-derived gate every sync
 * runs between its plan and its confirm. Invoked via reflection like
 * ensureClaimedServicesOffered's tests: the gate's logic is the unit, the call
 * site is one line in runScopes. The CLI side is pinned through the command's
 * cliVersion() seam — the real value in a test run is whatever pin this
 * checkout happens to be on. SyncCommand guards the account + environment
 * scopes and leaves app unguarded.
 */
function invokeCliMayApply(string $cli, array $pendingScopes): bool
{
    $command = new class($cli) extends SyncCommand
    {
        public function __construct(protected string $pinnedVersion)
        {
            parent::__construct();
        }

        protected function cliVersion(): string
        {
            return $this->pinnedVersion;
        }
    };

    $pending = Collection::make($pendingScopes)
        ->map(fn (string $scope): array => ['scope' => $scope, 'status' => StepResult::WOULD_SYNC])
        ->values();

    return (new ReflectionMethod($command, 'ensureCliMayApply'))->invoke($command, $pending);
}

/** @return array<int, array{name: string, args: array<string, mixed>}> */
function bindStampedEnvironment(?string $stamped, string $failure = 'NoSuchKey', int $status = 404): array
{
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => $stamped === null
            ? new S3Exception('missing', new Command('GetObject'), ['code' => $failure, 'response' => new Response($status)])
            : new Result(['Body' => $stamped . "\n"]),
    ], $captured);

    return $captured;
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('refuses a guarded-tier change when the environment was last synced by a newer release', function (string $scope): void {
    bindStampedEnvironment('v1.3.0');

    expect(invokeCliMayApply('v1.2.0', [$scope, 'app']))->toBeFalse();

    expect(test()->promptOutput->fetch())
        ->toContain('OLDER')
        ->toContain('v1.2.0')
        ->toContain('v1.3.0')
        ->toContain($scope . ' tier')
        ->toContain('composer update codinglabsau/yolo');
})->with(['account', 'environment']);

it('guards exactly the account + environment scopes on sync, and nothing on sync:app', function (): void {
    expect((new SyncCommand())->guardedScopes())->toBe(['account', 'environment']);
    expect((new SyncAppCommand())->guardedScopes())->toBe([]);
});

it('never reads the marker when nothing guarded is pending — the harm is the write, not the plan', function (array $pendingScopes): void {
    // A stamp far ahead of any checkout: were the marker read, it would refuse.
    $captured = bindStampedEnvironment('v999.0.0');

    expect(invokeCliMayApply('v1.2.0', $pendingScopes))->toBeTrue();
    expect(array_column($captured, 'name'))->not->toContain('GetObject');
    expect(test()->promptOutput->fetch())->toBe('');
})->with([
    'clean plan' => [[]],
    'pending only in the app tier' => [['app', 'app']],
]);

it('passes a guarded change when the CLI matches or outruns the stamp, or when either side is unordered', function (string $cli, ?string $stamped): void {
    bindStampedEnvironment($stamped);

    expect(invokeCliMayApply($cli, ['environment']))->toBeTrue();
    expect(test()->promptOutput->fetch())->toBe('');
})->with([
    'in step' => ['v1.3.0', 'v1.3.0'],
    'ahead of the stamp' => ['v1.4.0', 'v1.3.0'],
    'dev pin (unordered)' => ['dev-main', 'v1.3.0'],
    'unstamped environment — a greenfield first sync stamps it' => ['v1.3.0', null],
]);

it('passes when the marker is unreadable — a fenced tier fails open, never refused for what it cannot see', function (): void {
    bindStampedEnvironment(null, 'AccessDenied', 403);

    expect(invokeCliMayApply('v1.2.0', ['environment']))->toBeTrue();
});

it('passes through under the deploy gate — an app pin lagging the environment is the normal state between releases', function (): void {
    $captured = bindStampedEnvironment('v1.3.0');

    expect(DeployCheck::during(fn (): bool => invokeCliMayApply('v1.2.0', ['environment'])))->toBeTrue();
    expect(array_column($captured, 'name'))->not->toContain('GetObject');
});
