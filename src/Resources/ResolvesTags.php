<?php

namespace Codinglabs\Yolo\Resources;

use BackedEnum;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;

/**
 * Tags and name derived from the resource's scope() — the single source of
 * truth. App-scoped resources carry the yolo:app owner tag; env- and
 * account-scoped (shared) resources resolve to Name only. The yolo:environment
 * baseline is still added by Aws::tags()/expectedTags() at write time.
 *
 * Driving everything off scope() means a resource declares its tier once and
 * its name exclusivity, its owner tag, and its writing command all follow — they
 * can't drift apart.
 *
 * @phpstan-require-implements Resource
 */
trait ResolvesTags
{
    public function tags(): array
    {
        return [
            'Name' => $this->name(),
            ...($this->scope() === Scope::App ? ['yolo:app' => Manifest::name()] : []),
        ];
    }

    /**
     * The keyed resource name with exclusivity derived from scope() — so the
     * name and the yolo:app tag share one source and can't disagree. App →
     * yolo-{env}-{app}-{suffix}; Env/Account → yolo-{env}-{suffix}.
     */
    protected function keyedName(string|BackedEnum|null $suffix = null): string
    {
        return Helpers::keyedResourceName($suffix, exclusive: $this->scope()->exclusive());
    }
}
