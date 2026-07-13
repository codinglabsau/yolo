<?php

use Codinglabs\Yolo\Commands\DeployCommand;

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
        'tenants' => [
            'alpha' => ['domain' => 'alpha.example.com'],
            'worker' => [],
            'beta' => ['domain' => 'beta.example.com'],
        ],
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
