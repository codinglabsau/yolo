<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sts\StsClient;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

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

function invokeAccountGuard(): void
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'ensureManifestAccountMatchesProfile');

    $method->invoke($command);
}

it('returns silently when the manifest account matches the resolved STS account', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    bindMockStsClient('848509375702');

    invokeAccountGuard();
})->throwsNoExceptions();

it('throws IntegrityCheckException on mismatch with both account IDs + env var name in the message', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    bindMockStsClient('999999999999');

    try {
        invokeAccountGuard();
        $this->fail('expected IntegrityCheckException was not thrown');
    } catch (IntegrityCheckException $e) {
        expect($e->getMessage())
            ->toContain('848509375702')
            ->toContain('999999999999')
            ->toContain('YOLO_TESTING_AWS_PROFILE');
    }
});

it('returns silently (no STS call) when the manifest declares no account', function () {
    writeManifest([
        'aws' => ['region' => 'ap-southeast-2'],
    ]);

    // No mock bound — if the method tried to call STS it would fail with a credential error.
    invokeAccountGuard();
})->throwsNoExceptions();
