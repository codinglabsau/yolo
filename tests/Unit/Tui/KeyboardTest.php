<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Keyboard;

it('decodes arrow escape sequences', function (): void {
    expect(Keyboard::decode("\e[A"))->toBe('up')
        ->and(Keyboard::decode("\e[B"))->toBe('down')
        ->and(Keyboard::decode("\eOC"))->toBe('right')
        ->and(Keyboard::decode("\e[D"))->toBe('left');
});

it('decodes the control keys by name', function (): void {
    expect(Keyboard::decode("\r"))->toBe('enter')
        ->and(Keyboard::decode("\t"))->toBe('tab')
        ->and(Keyboard::decode("\x03"))->toBe('ctrl-c')
        ->and(Keyboard::decode("\e"))->toBe('esc');
});

it('passes printable keys through unchanged', function (): void {
    expect(Keyboard::decode('s'))->toBe('s')
        ->and(Keyboard::decode('q'))->toBe('q');
});
