<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Manifest;

trait ResolvesDatabases
{
    protected function databases(): array
    {
        return Manifest::isMultitenanted()
            ? [
                env('DB_DATABASE'), // landlord
                ...array_keys(Manifest::tenants()),
            ]
            : [env('DB_DATABASE')];
    }
}
