<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Closure;
use RuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * The temporary-index pattern over Typesense aliases: build a timestamped
 * collection beside the live one, swap the alias (atomic on the cluster), delete
 * the old one, then replay `updated_at >= started` through Scout's normal path.
 *
 * The first run per model migrates the layout: Scout created a LITERAL
 * collection under the searchable name, and Typesense won't alias over an
 * existing collection name — so that one time the literal collection is deleted
 * immediately before the alias lands (a sub-second gap).
 *
 * Deletions during the build window are the one thing the replay can't see — a
 * record deleted mid-build lingers until the next reimport.
 */
class ZeroDowntimeReimport
{
    protected const int CHUNK = 500;

    public function __construct(protected TypesenseClient $typesense) {}

    /**
     * @param  class-string<Model&SearchableModel>  $modelClass
     * @param  (Closure(string): void)|null  $report
     * @return array{alias: string, collection: string, documents: int, replayed: int}
     */
    public function reimport(string $modelClass, ?Closure $report = null): array
    {
        $model = new $modelClass();
        $report ??= fn (string $message): null => null;

        $alias = $model->searchableAs();
        $started = Carbon::now();

        // The live index is under an alias (steady state), a literal collection
        // (pre-migration Scout layout), or nowhere (wiped cluster).
        $previous = $this->typesense->aliasTarget($alias);
        $literal = $previous === null && $this->typesense->collection($alias) !== null;

        $collection = sprintf('%s_%s', $alias, $started->format('YmdHisv'));

        $this->typesense->createCollection([...$this->schema($model), 'name' => $collection]);

        try {
            $documents = $this->import($model, $collection, $report);

            // An alias can't share a name with a live collection — the one-time
            // sub-second serving gap.
            if ($literal) {
                $this->typesense->deleteCollection($alias);
            }

            $this->typesense->upsertAlias($alias, $collection);
        } catch (\Throwable $e) {
            // Don't orphan the half-built collection: the heal loop retries a
            // failing rebuild every few minutes, and accumulating partials on a
            // memory-bound cluster is itself a cluster-killer. Best-effort.
            try {
                $this->typesense->deleteCollection($collection);
            } catch (\Throwable) {
                // reported via the original exception
            }

            throw $e;
        }

        if ($previous !== null) {
            $this->typesense->deleteCollection($previous);
        }

        $replayed = $this->replay($model, $started);

        $report(sprintf('%s: %d documents into %s, %d changed rows replayed', $modelClass, $documents, $collection, $replayed));

        return ['alias' => $alias, 'collection' => $collection, 'documents' => $documents, 'replayed' => $replayed];
    }

    /**
     * Resolved exactly as Scout's Typesense engine does — model method first,
     * then `model-settings` — so the rebuilt collection matches the engine's.
     *
     * @return array<string, mixed>
     */
    protected function schema(Model $model): array
    {
        if (method_exists($model, 'typesenseCollectionSchema')) {
            return (array) $model->typesenseCollectionSchema();
        }

        $schema = config('scout.typesense.model-settings.' . $model::class . '.collection-schema');

        if (! is_array($schema) || $schema === []) {
            throw new RuntimeException(sprintf(
                '%s declares no Typesense schema — add a collection-schema under scout.typesense.model-settings (or a typesenseCollectionSchema() method) so the collection can be rebuilt.',
                $model::class,
            ));
        }

        return $schema;
    }

    /**
     * Documents are shaped exactly as the engine's update() does, so the rebuilt
     * index is byte-for-byte what Scout would have written.
     *
     * @param  Model&SearchableModel  $model
     */
    protected function import(Model $model, string $collection, Closure $report): int
    {
        $imported = 0;

        $this->searchableQuery($model)->chunkById(self::CHUNK, function ($models) use ($collection, &$imported, $report): void {
            $softDelete = in_array(SoftDeletes::class, class_uses_recursive($models->first()), true) && config('scout.soft_delete', false);

            // Scout's per-batch hook — apps use it for batch eager-loading, so
            // skipping it rebuilds correctly but one lazy load at a time.
            $models = $models->first()->makeSearchableUsing($models);

            $documents = $models
                ->filter(fn ($model): bool => $model->shouldBeSearchable())
                ->map(function ($model) use ($softDelete): ?array {
                    if ($softDelete) {
                        $model->pushSoftDeleteMetadata();
                    }

                    $searchable = $model->toSearchableArray();

                    return $searchable === [] ? null : array_merge($searchable, $model->scoutMetadata());
                })
                ->filter()
                ->values()
                ->all();

            $this->typesense->importDocuments($collection, $documents);

            $imported += count($documents);

            $report(sprintf('  … %d documents', $imported));
        }, $model->getKeyName());

        return $imported;
    }

    /**
     * The same base query Scout's own import walks: trashed rows included when
     * Scout indexes soft deletes (dropping them would diverge from the index
     * Scout maintains); `makeAllSearchableUsing` is protected on the trait, so
     * reflection invokes it.
     *
     * @param  Model&SearchableModel  $model
     * @return EloquentBuilder<Model&SearchableModel>
     */
    protected function searchableQuery(Model $model): EloquentBuilder
    {
        $query = $model->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && config('scout.soft_delete', false)) {
            $query = $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        if (method_exists($model, 'makeAllSearchableUsing')) {
            $scoped = (new \ReflectionMethod($model, 'makeAllSearchableUsing'))->invoke($model, $query);

            return $scoped instanceof EloquentBuilder ? $scoped : $query;
        }

        return $query;
    }

    /**
     * Anything that changed during the import went to the OLD collection and
     * died with it — push it through Scout's normal path, which now lands on the
     * new collection via the alias. Models without timestamps can't be windowed.
     *
     * @param  Model&SearchableModel  $model
     */
    protected function replay(Model $model, Carbon $started): int
    {
        if (! $model->usesTimestamps() || $model->getUpdatedAtColumn() === null) {
            return 0;
        }

        $replayed = 0;

        // A minute's buffer absorbs writer clock skew and transactions that
        // committed after the chunk passed their id; replay is an idempotent upsert.
        $this->searchableQuery($model)
            ->where($model->qualifyColumn($model->getUpdatedAtColumn()), '>=', $started->copy()->subMinute())
            ->chunkById(self::CHUNK, function ($models) use ($model, &$replayed): void {
                $searchable = $models->filter(fn ($changed): bool => $changed->shouldBeSearchable())->values();

                if ($searchable->isNotEmpty()) {
                    $model->queueMakeSearchable($searchable->toBase());
                }

                $replayed += $searchable->count();
            }, $model->getKeyName());

        return $replayed;
    }
}
