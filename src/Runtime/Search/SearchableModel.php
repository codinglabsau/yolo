<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The slice of Scout's Searchable trait the runtime search tools call,
 * expressed as an interface purely for static analysis — a trait can't
 * appear in a type expression, so "a model using Searchable" is typed as
 * `Model&SearchableModel` in docblocks. No model ever implements this
 * (which is why it must never appear as a NATIVE parameter type); the
 * discovery layer ({@see SearchableModels}) is what guarantees at runtime
 * that every class it hands out actually carries the trait.
 */
interface SearchableModel
{
    public function searchableAs(): string;

    public function shouldBeSearchable(): bool;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array;

    /**
     * @return array<string, mixed>
     */
    public function scoutMetadata(): array;

    public function pushSoftDeleteMetadata(): void;

    /**
     * @param  Collection<int, mixed>  $models
     */
    public function queueMakeSearchable(Collection $models): void;

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model&SearchableModel>  $models
     * @return \Illuminate\Database\Eloquent\Collection<int, Model&SearchableModel>
     */
    public function makeSearchableUsing(\Illuminate\Database\Eloquent\Collection $models): \Illuminate\Database\Eloquent\Collection;
}
