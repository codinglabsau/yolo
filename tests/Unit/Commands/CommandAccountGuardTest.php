<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sts\StsClient;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Output\BufferedOutput;

function bindMockStsClient(string $account): void
{
    $mock = new MockHandler();
    $mock->append(new Result(['Account' => $account]));

    Helpers::app()->instance('sts', new StsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function invokeAccountGuard(): bool
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'ensureManifestAccountMatchesProfile');

    return $method->invoke($command);
}

beforeEach(function () {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('returns true when the manifest account matches the resolved STS account', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    bindMockStsClient('848509375702');

    expect(invokeAccountGuard())->toBeTrue();
});

it('returns false and surfaces both account IDs + env var name on mismatch', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    bindMockStsClient('999999999999');

    expect(invokeAccountGuard())->toBeFalse();

    $output = test()->promptOutput->fetch();

    expect($output)->toContain('848509375702')
        ->and($output)->toContain('999999999999')
        ->and($output)->toContain('YOLO_TESTING_AWS_PROFILE');
});

it('returns true (no STS call) when the manifest declares no account', function () {
    writeManifest([
        'aws' => ['region' => 'ap-southeast-2'],
    ]);

    // No mock bound — if the method tried to call STS it would fail with a credential error.
    expect(invokeAccountGuard())->toBeTrue();
});
