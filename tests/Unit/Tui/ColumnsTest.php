<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Columns;

it('pads each cell before colouring so tags do not skew alignment', function (): void {
    $row = Columns::row([['typesense', 12, Theme::Primary], ['provision', 10, Theme::Healthy]]);

    expect($row)->toContain('<fg=#2DD4D4>typesense   </>')   // padded to 12 inside the tag
        ->toContain('<fg=#A3E635>provision </>');             // padded to 10 inside the tag
});

it('renders an uncoloured cell as plain padded text', function (): void {
    expect(Columns::row([['svc', 5, null]]))->toBe('  svc  ');
});
