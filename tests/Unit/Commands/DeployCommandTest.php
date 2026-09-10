<?php

use Codinglabs\Yolo\Commands\DeployCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Steps\Sync\App\PublishAppManifestStep;

function deployAppUrlLines(): array
{
    return (new ReflectionMethod(DeployCommand::class, 'appUrlLines'))->invoke(new DeployCommand());
}

it('ends the deploy summary with the solo app URL', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
    ]);

    expect(deployAppUrlLines())
        ->toBe(['', '  <options=bold>Live</> <href=https://app.example.com>https://app.example.com</>']);
});

it('lists every tenant URL for a multi-tenant app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['tenants' => [
            'alpha' => ['domain' => 'alpha.example.com'],
            'worker' => [],
            'beta' => ['domain' => 'beta.example.com'],
        ]],
    ]);

    $lines = deployAppUrlLines();

    expect($lines)->toHaveCount(3)   // leading blank + two domain lines
        ->and($lines[1])->toContain('https://alpha.example.com')
        ->and($lines[2])->toContain('https://beta.example.com');
});

it('prints no URL for a headless app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect(deployAppUrlLines())->toBe([]);
});

it('never publishes the app claim — that is sync:app\'s, so the claim stays an admin-written file', function (): void {
    // The claim names the app's bring-your-own data bucket and the env data
    // documents grant on it env-wide; a deploy (CI, deployer tier) must never be
    // able to write it. The in-sync gate still plans the publish step, so a claim
    // that lags the manifest is drift the deploy refuses on.
    expect((new DeployCommand())->steps())->not->toContain(PublishAppManifestStep::class);
    expect((new SyncAppCommand())->scopes()['app'])->toContain(PublishAppManifestStep::class);
});
