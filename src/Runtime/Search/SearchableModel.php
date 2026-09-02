<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Static-analysis only: a trait can't appear in a type expression, so "a model
 * using Searchable" is typed `Model&SearchableModel` in docblocks. No model ever
 * implements this, so it must never appear as a NATIVE parameter type;
 * {@see SearchableModels} guarantees the trait at runtime.
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
