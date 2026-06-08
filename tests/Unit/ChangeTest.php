<?php

declare(strict_types=1);

use Codinglabs\Yolo\Change;

it('formats scalar, bool, null and array values into display strings', function (): void {
    expect(Change::make('count', 1, 2)->from)->toBe('1');
    expect(Change::make('count', 1, 2)->to)->toBe('2');

    $bool = Change::make('flag', false, true);
    expect($bool->from)->toBe('false');
    expect($bool->to)->toBe('true');

    $absent = Change::make('versioning', null, 'Enabled');
    expect($absent->from)->toBeNull();
    expect($absent->to)->toBe('Enabled');

    expect(Change::make('rules', ['a' => 1], ['a' => 2])->to)->toBe('{"a":2}');
});

it('keeps explicit string from/to for document-level comparisons', function (): void {
    $change = new Change('bucket-policy', null, 'alb-access-log-delivery');

    expect($change->attribute)->toBe('bucket-policy');
    expect($change->from)->toBeNull();
    expect($change->to)->toBe('alb-access-log-delivery');
});

it('renders a single-line comparison, marking an absent side', function (): void {
    expect(Change::make('idle_timeout', 30, 60)->describe())->toBe('idle_timeout: 30 → 60');
    expect(Change::make('versioning', null, 'Enabled')->describe())->toBe('versioning: <absent> → Enabled');
});
