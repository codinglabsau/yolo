<?php

use Aws\Result;
use Aws\Command;
use Laravel\Prompts\Prompt;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\DeployCheck;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The older-CLI refusal (ensureCliNotOlderThanEnvironment) — shared by sync,
 * sync:environment and sync:account. Invoked via reflection like
 * ensureClaimedServicesOffered's tests: the gate's logic is the unit, the
 * call sites are one-liners. The CLI side is pinned through the command's
 * cliVersion() seam — the real value in a test run is whatever pin this
 * checkout happens to be on.
 */
function invokeCliNotOlderThanEnvironment(string $cli): bool
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

    $method = new ReflectionMethod($command, 'ensureCliNotOlderThanEnvironment');

    return $method->invoke($command);
}

function bindStampedEnvironment(?string $stamped, string $failure = 'NoSuchKey', int $status = 404): void
{
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => $stamped === null
            ? new S3Exception('missing', new Command('GetObject'), ['code' => $failure, 'response' => new Response($status)])
            : new Result(['Body' => $stamped . "\n"]),
    ], $captured);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('refuses when the environment was last synced by a newer release', function (): void {
    bindStampedEnvironment('v1.3.0');

    expect(invokeCliNotOlderThanEnvironment('v1.2.0'))->toBeFalse();

    expect(test()->promptOutput->fetch())
        ->toContain('OLDER')
        ->toContain('v1.2.0')
        ->toContain('v1.3.0')
        ->toContain('composer update codinglabsau/yolo');
});

it('passes when the CLI matches or outruns the stamp, or when either side is unordered', function (string $cli, ?string $stamped): void {
    bindStampedEnvironment($stamped);

    expect(invokeCliNotOlderThanEnvironment($cli))->toBeTrue();
    expect(test()->promptOutput->fetch())->toBe('');
})->with([
    'in step' => ['v1.3.0', 'v1.3.0'],
    'ahead of the stamp' => ['v1.4.0', 'v1.3.0'],
    'dev pin (unordered)' => ['dev-main', 'v1.3.0'],
    'unstamped environment — a greenfield first sync stamps it' => ['v1.3.0', null],
]);

it('passes when the marker is unreadable — a fenced tier fails open, never refused for what it cannot see', function (): void {
    bindStampedEnvironment(null, 'AccessDenied', 403);

    expect(invokeCliNotOlderThanEnvironment('v1.2.0'))->toBeTrue();
});

it('passes through under the deploy gate — an app pin lagging the environment is the normal state between releases', function (): void {
    bindStampedEnvironment('v1.3.0');

    expect(DeployCheck::during(fn (): bool => invokeCliNotOlderThanEnvironment('v1.2.0')))->toBeTrue();
});
