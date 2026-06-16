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
    $method = new ReflectionMethod($command, 'ensureAccountMatchesProfile');

    return $method->invoke($command);
}

beforeEach(function (): void {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('returns true when the manifest account matches the resolved STS account', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindMockStsClient('111111111111');

    expect(invokeAccountGuard())->toBeTrue();
});

it('returns false and surfaces both account IDs + env var name on mismatch', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindMockStsClient('999999999999');

    expect(invokeAccountGuard())->toBeFalse();

    $output = test()->promptOutput->fetch();

    expect($output)->toContain('111111111111')
        ->and($output)->toContain('999999999999')
        ->and($output)->toContain('YOLO_TESTING_AWS_PROFILE');
});
