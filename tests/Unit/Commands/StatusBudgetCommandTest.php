<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Commands\StatusBudgetCommand;

it('formats spend against the budget, the strategy, or notes no budget set', function (): void {
    expect(StatusBudgetCommand::formatBudget(42.1, 100.0, 'balanced'))
        ->toContain('$42.10')
        ->toContain('$100.00')
        ->toContain('42%')
        ->toContain('strategy: balanced');

    // No spend data yet (Cost Explorer tag not activated) → em dash, cap still shown.
    expect(StatusBudgetCommand::formatBudget(null, 100.0, 'lean'))
        ->toContain('—')
        ->toContain('$100.00')
        ->toContain('strategy: lean');

    // No cap declared → reports spend with a "no budget set" note.
    expect(StatusBudgetCommand::formatBudget(30.0, null, 'conservative'))
        ->toContain('$30.00')
        ->toContain('no budget set')
        ->toContain('strategy: conservative');
});

it('registers status:budget with --json', function (): void {
    $command = new StatusBudgetCommand();

    expect($command->getName())->toBe('status:budget')
        ->and($command->getDefinition()->hasOption('json'))->toBeTrue()
        ->and($command->getDefinition()->hasArgument('environment'))->toBeTrue();
});

it('accepts the budget block in the manifest schema', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'budget' => ['amount' => 100, 'strategy' => 'balanced'],
    ]);

    expect(Manifest::unknownKeys())->toBe([]);
});
