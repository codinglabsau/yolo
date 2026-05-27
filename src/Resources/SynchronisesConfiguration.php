<?php

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Change;

/**
 * Optional Resource capability: reconcile live configuration (beyond tags) when
 * the resource already exists. The base Resource contract only guarantees tag
 * sync; resources whose config can drift after creation implement this so `sync`
 * pushes config changes onto the existing resource instead of only fixing tags.
 *
 * Every implementation reads live state and diffs it against desired, returning
 * the attributes that differ as Change[] (empty = in sync). It applies the fix
 * only when $apply is true — a dry-run passes false to compute the diff WITHOUT
 * writing, which is how `sync --dry-run` reports what it would change.
 */
interface SynchronisesConfiguration
{
    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array;
}
