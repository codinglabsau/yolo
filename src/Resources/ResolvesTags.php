<?php

namespace Codinglabs\Yolo\Resources;

use BackedEnum;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;

/**
 * Tags and name derived from the resource's scope() — the single source of
 * truth. Every YOLO-managed resource carries a `yolo:scope` tag matching its
 * scope() (app / env / account); App-scoped resources additionally carry the
 * `yolo:app` owner tag. The `yolo:environment` baseline is still added by
 * Aws::tags()/expectedTags() at write time.
 *
 * The `yolo:scope` tag is what lets `audit` tell a YOLO-declared env-shared
 * resource (ALB, VPC, subnets) apart from a genuinely rogue one — without it,
 * "no `yolo:app` tag" was indistinguishable from "shouldn't be here".
 *
 * Driving everything off scope() means a resource declares its tier once and
 * its name exclusivity, its owner tag, the scope tag and its writing command
 * all follow — they can't drift apart.
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
