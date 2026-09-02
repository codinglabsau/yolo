<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * Drives the resource's name, its tags and which sync tier is its single writer
 * at once, so the three can't drift apart.
 */
enum Scope: string
{
    case App = 'app';

    case Env = 'env';

    case Account = 'account';

    public function exclusive(): bool
    {
        return $this === self::App;
    }

    public function shared(): bool
    {
        return $this !== self::App;
    }
}
