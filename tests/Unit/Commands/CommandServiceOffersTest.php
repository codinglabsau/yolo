<?php

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The claim-without-offer hard error (ensureClaimedServicesOffered) — shared
 * by build, deploy (via build) and sync:app/sync. Invoked via reflection like
 * ensureManifestIntegrity's tests: the gate's logic is the unit, the call
 * sites are one-liners.
 */
function invokeClaimedServicesOffered(): bool
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'ensureClaimedServicesOffered');

    return $method->invoke($command);
}

beforeEach(function (): void {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('passes when the app uses no env-backed services (app-side services need no env entry)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['mediaconvert', 'rekognition'],
    ]);

    // No S3 mock bound: the gate must answer without any AWS read.
    expect(invokeClaimedServicesOffered())->toBeTrue();
});

it('hard-fails when the app uses an env-backed service the environment manifest does not declare', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services: {  }\n"], $captured);

    expect(invokeClaimedServicesOffered())->toBeFalse();

    expect(test()->promptOutput->fetch())
        ->toContain('uses the ivs service')
        ->toContain('services.ivs')
        ->toContain('environment:manifest:pull');
});

it('passes when the environment manifest declares every env-backed service the app uses', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "services:\n  ivs: {}\n"], $captured);

    expect(invokeClaimedServicesOffered())->toBeTrue();
});

it('defers to the first sync on a greenfield environment with no manifest object yet', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs'],
    ]);

    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    expect(invokeClaimedServicesOffered())->toBeTrue();
});
