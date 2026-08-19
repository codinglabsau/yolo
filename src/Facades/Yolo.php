<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Codinglabs\Yolo\ManifestReader manifest()
 *
 * @see \Codinglabs\Yolo\Runtime\Yolo
 */
class Yolo extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return \Codinglabs\Yolo\Runtime\Yolo::class;
    }
}
