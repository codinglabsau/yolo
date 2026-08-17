<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Destroy\App\WithdrawAppDnsRecordsStep;

it('withdraws records from every zone the app\'s own hosts span — not just the primary apex', function (): void {
    $captured = [];
    bindMockRoute53Client(
        [
            ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
            ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
        ],
        $captured,
        [
            ['Type' => 'A', 'Name' => 'example.com.'],
            ['Type' => 'A', 'Name' => 'www.example.com.'],
            ['Type' => 'A', 'Name' => 'app.example.io.'],
            // not ours — a sibling app's record in the same zone
            ['Type' => 'A', 'Name' => 'other.example.io.'],
        ],
    );

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new WithdrawAppDnsRecordsStep())(['dry-run' => false]))->toBe(StepResult::DELETED);

    $deleteCalls = collect($captured)
        ->where('name', 'ChangeResourceRecordSets')
        ->filter(fn (array $call): bool => collect($call['args']['ChangeBatch']['Changes'])->contains(fn (array $change): bool => $change['Action'] === 'DELETE'));

    $deletedNames = $deleteCalls
        ->flatMap(fn (array $call): array => $call['args']['ChangeBatch']['Changes'])
        ->pluck('ResourceRecordSet.Name')
        ->map(fn (string $name): string => rtrim($name, '.'))
        ->all();

    expect($deletedNames)->toEqualCanonicalizing(['example.com', 'www.example.com', 'app.example.io'])
        ->and($deletedNames)->not->toContain('other.example.io');
});

it('reports SKIPPED when no zone holds any of the app\'s records', function (): void {
    $captured = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
        ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
    ], $captured, []);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new WithdrawAppDnsRecordsStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});
