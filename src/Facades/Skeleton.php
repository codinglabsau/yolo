<?php

namespace Codinglabs\Skeleton\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Codinglabs\Skeleton\Skeleton
 */
class Skeleton extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Codinglabs\Skeleton\Skeleton::class;
    }
}
