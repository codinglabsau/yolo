<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Layout;

it('pads a short body so the footer pins to the bottom row', function (): void {
    $frame = Layout::fit(['top'], ['body'], ['footer'], 6);

    expect($frame)->toHaveCount(6)
        ->and($frame[0])->toBe('top')
        ->and($frame[1])->toBe('body')
        ->and($frame[2])->toBe('')   // padding
        ->and($frame[4])->toBe('')
        ->and($frame[5])->toBe('footer'); // pinned last
});

it('truncates a body that overflows its budget', function (): void {
    $frame = Layout::fit(['top'], ['a', 'b', 'c', 'd', 'e'], ['footer'], 4);

    // budget = 4 - 1 - 1 = 2 body rows
    expect($frame)->toBe(['top', 'a', 'b', 'footer']);
});

it('always returns exactly the requested height', function (int $height): void {
    $frame = Layout::fit(['t1', 't2'], ['b1', 'b2', 'b3'], ['f1', 'f2'], $height);

    expect($frame)->toHaveCount(max(0, $height));
})->with([0, 1, 4, 7, 10, 20]);

it('lets the top chrome win when chrome alone overflows the terminal', function (): void {
    $frame = Layout::fit(['t1', 't2', 't3'], ['body'], ['footer'], 2);

    // No budget for the body; clip to height, top chrome first.
    expect($frame)->toBe(['t1', 't2']);
});

it('handles a zero height', function (): void {
    expect(Layout::fit(['top'], ['body'], ['footer'], 0))->toBe([]);
});

describe('bodyBudget', function (): void {
    it('subtracts the chrome from the height', function (): void {
        expect(Layout::bodyBudget(4, 2, 20))->toBe(14);
    });

    it('never goes negative', function (): void {
        expect(Layout::bodyBudget(4, 2, 3))->toBe(0);
    });
});
