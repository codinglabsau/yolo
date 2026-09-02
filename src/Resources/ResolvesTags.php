<?php

namespace Codinglabs\Yolo\Resources;

use BackedEnum;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;

/**
 * Name and tags derive from scope() so a resource declares its tier once and its
 * name exclusivity, owner tag, scope tag and writing command can't drift apart.
 * `yolo:scope` is what lets `audit` tell a declared env-shared resource from a
 * rogue one. The `yolo:environment` baseline is added by Aws::tags() at write time.
 *
 * @phpstan-require-implements Resource
 */
trait ResolvesTags
{
    public function tags(): array
    {
        return [
            'Name' => $this->name(),
            'yolo:scope' => $this->scope()->value,
            ...($this->scope() === Scope::App ? ['yolo:app' => Manifest::name()] : []),
        ];
    }

    protected function keyedName(string|BackedEnum|null $suffix = null): string
    {
        return Helpers::keyedResourceName($suffix, exclusive: $this->scope()->exclusive());
    }
}
