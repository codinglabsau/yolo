<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Viewport;

/** @return array<int, string> */
function lines(int $count): array
{
    return array_map(static fn (int $n): string => 'line ' . $n, range(1, $count));
}

it('returns the whole list with no indicator when it fits', function (): void {
    $viewport = new Viewport();

    $window = $viewport->window(lines(3), 5);

    expect($window)->toBe(['line 1', 'line 2', 'line 3']);
});

it('reserves one row for the indicator when clipped', function (): void {
    $viewport = new Viewport();

    // 10 lines into a 5-row body: 4 content rows + 1 indicator row.
    $window = $viewport->window(lines(10), 5);

    expect($window)->toHaveCount(5);
});

it('follows the tail by default — newest lines at the bottom', function (): void {
    $viewport = new Viewport();

    $window = $viewport->window(lines(10), 5);

    // Tail-pinned: last 4 content lines, then the indicator showing rows above.
    expect(array_slice($window, 0, 4))->toBe(['line 7', 'line 8', 'line 9', 'line 10'])
        ->and($window[4])->toContain('▲ 6 more');
});

it('stays pinned to the new bottom as lines arrive', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(10), 5);
    $window = $viewport->window(lines(12), 5);

    expect(array_slice($window, 0, 4))->toBe(['line 9', 'line 10', 'line 11', 'line 12'])
        ->and($viewport->followingTail())->toBeTrue();
});

it('disarms follow-tail on scroll up and freezes the offset', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(10), 5);   // tail: offset 6
    $viewport->scrollUp(2);            // offset 4
    $window = $viewport->window(lines(10), 5);

    expect($viewport->followingTail())->toBeFalse()
        ->and(array_slice($window, 0, 4))->toBe(['line 5', 'line 6', 'line 7', 'line 8'])
        ->and($window[4])->toContain('▲ 4 more')
        ->and($window[4])->toContain('▼ 2 more');
});

it('stays put as new lines arrive while scrolled up', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(10), 5);
    $viewport->scrollUp(3);            // offset 3, tail disarmed
    $viewport->window(lines(15), 5);   // more lines arrive

    expect($viewport->offset())->toBe(3)
        ->and($viewport->followingTail())->toBeFalse();
});

it('re-arms follow-tail when scrolled back to the bottom', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(10), 5);
    $viewport->scrollUp(4);
    $viewport->window(lines(10), 5);
    $viewport->scrollDown(100);        // overscroll past the bottom
    $viewport->window(lines(10), 5);

    expect($viewport->followingTail())->toBeTrue()
        ->and($viewport->offset())->toBe(6);
});

it('re-clamps a stranded offset when the list shrinks', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(20), 5);
    $viewport->scrollUp(10);           // offset 6 (clamped from maxOffset)
    expect($viewport->offset())->toBe(6);

    $viewport->window(lines(8), 5);    // list shrinks; maxOffset now 4
    expect($viewport->offset())->toBe(4);
});

it('jumps to the top and to the tail', function (): void {
    $viewport = new Viewport();

    $viewport->window(lines(10), 5);
    $viewport->toTop();
    $window = $viewport->window(lines(10), 5);

    expect($viewport->offset())->toBe(0)
        ->and(array_slice($window, 0, 4))->toBe(['line 1', 'line 2', 'line 3', 'line 4'])
        ->and($window[4])->toContain('▼ 6 more')
        ->and($window[4])->not->toContain('▲');

    $viewport->toTail();
    $viewport->window(lines(10), 5);
    expect($viewport->followingTail())->toBeTrue();
});

it('returns nothing for a non-positive height', function (): void {
    expect((new Viewport())->window(lines(10), 0))->toBe([]);
});

describe('indicator', function (): void {
    it('is empty when nothing is clipped', function (): void {
        expect(Viewport::indicator(0, 3, 5))->toBe('');
    });

    it('shows only below when at the top', function (): void {
        expect(Viewport::indicator(0, 10, 4))
            ->toContain('▼ 6 more')
            ->not->toContain('▲');
    });

    it('shows only above when at the bottom', function (): void {
        expect(Viewport::indicator(6, 10, 4))
            ->toContain('▲ 6 more')
            ->not->toContain('▼');
    });

    it('shows both when in the middle', function (): void {
        expect(Viewport::indicator(3, 10, 4))
            ->toContain('▲ 3 more')
            ->toContain('▼ 3 more');
    });
});
