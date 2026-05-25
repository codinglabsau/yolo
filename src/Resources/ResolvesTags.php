<?php

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Manifest;

/**
 * The single source of a resource's tags: its `Name`, plus the `yolo:app` owner
 * tag when the resource is marked AppScoped. The `yolo:environment` baseline is
 * still added by Aws::tags()/expectedTags() at write time.
 *
 * Driving yolo:app off the AppScoped marker means a new app resource gets the
 * tag just by declaring the interface — no remembering to add it to tags(), the
 * gap that previously let resources slip through untagged. The instanceof check
 * is also ready for shared resources to adopt this trait later (they'd resolve
 * to Name only).
 *
 * @phpstan-require-implements Resource
 */
trait ResolvesTags
{
    public function tags(): array
    {
        return [
            'Name' => $this->name(),
            ...($this instanceof AppScoped ? ['yolo:app' => Manifest::name()] : []),
        ];
    }
}
