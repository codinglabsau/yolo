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
 * Rebuild one model's search collection with zero blackout — the temporary
 * index pattern, which Typesense supports natively through collection
 * aliases (an alias name is accepted anywhere a collection name is):
 *
 *  1. build a timestamped collection beside the live one, from the model's
 *     declared schema;
 *  2. import every searchable record into it directly — the live index keeps
 *     serving untouched the whole time;
 *  3. swap the alias onto the new collection (atomic on the cluster) and
 *     delete the old one;
 *  4. replay records that changed during the build window through Scout's
 *     normal path — the database is the source of truth, so "how do we catch
 *     up" is always answered by `updated_at >= started`.
 *
 * The first run per model migrates the layout: Scout created a LITERAL
 * collection under the model's searchable name, and Typesense won't alias
 * over an existing collection name — so that one time, the literal
 * collection is deleted immediately before the alias lands (a sub-second
 * gap). Every run after that is a pure alias flip.
 *
 * This replaces `scout:import --fresh` wholesale: exact mirror (orphans die
 * with the old collection), schema changes applied (the new collection is
 * built from the current schema), and no index blackout. Deletions during
 * the build window are the one thing the replay can't see — a record
 * deleted mid-build lingers until the next reimport, the same gap the
 * temporary-index pattern has everywhere.
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

        // Where does the live index actually live? Under an alias (steady
        // state), as a literal collection (pre-migration Scout layout), or
        // nowhere (a wiped cluster — the degenerate case, where there is
        // nothing to keep serving and nothing to delete).
        $previous = $this->typesense->aliasTarget($alias);
        $literal = $previous === null && $this->typesense->collection($alias) !== null;

        $collection = sprintf('%s_%s', $alias, $started->format('YmdHisv'));

        $this->typesense->createCollection([...$this->schema($model), 'name' => $collection]);

        try {
            $documents = $this->import($model, $collection, $report);

            // The one-time layout migration: an alias can't share a name with
            // a live collection, so the literal one goes first — the only
            // serving gap this command ever creates, sub-second, once per
            // model ever.
            if ($literal) {
                $this->typesense->deleteCollection($alias);
            }

            $this->typesense->upsertAlias($alias, $collection);
        } catch (\Throwable $e) {
            // A failed build must not orphan the half-built collection: the
            // heal loop retries a persistently-failing rebuild every few
            // minutes, and on a memory-bound cluster accumulating partials
            // is itself a cluster-killer. Best-effort — if the cluster is
            // unreachable the delete fails too, and the original failure is
            // the one worth surfacing.
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
     * The collection schema, resolved exactly as Scout's Typesense engine
     * does — the model's own method first, then `model-settings` — so the
     * rebuilt collection is the one the engine would have created.
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
     * Chunk every searchable record into the temporary collection, shaping
     * each document exactly as the engine's update() does (searchable array
     * + scout metadata, soft-delete metadata when configured, empties
     * skipped) — so the rebuilt index is byte-for-byte what Scout would
     * have written.
     *
     * @param  Model&SearchableModel  $model
     */
    protected function import(Model $model, string $collection, Closure $report): int
    {
        $imported = 0;

        $this->searchableQuery($model)->chunkById(self::CHUNK, function ($models) use ($collection, &$imported, $report): void {
            $softDelete = in_array(SoftDeletes::class, class_uses_recursive($models->first()), true) && config('scout.soft_delete', false);

            // The per-batch hook Scout's own write path runs before every
            // engine update — apps use it for batch eager-loading, so
            // skipping it would rebuild correctly but one lazy load at a time.
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
     * The same base query Scout's own import walks: trashed rows included
     * when Scout indexes soft deletes (they carry `__soft_deleted` metadata,
     * so dropping them would diverge from the index Scout maintains), and
     * the model's `makeAllSearchableUsing` scope applied (eager loads etc.) —
     * protected on the trait, so reflection invokes it (visibility-blind
     * since PHP 8.1).
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
     * Replay the build window: anything that changed while the temporary
     * collection was importing went to the OLD collection and died with it,
     * so push those rows again through Scout's normal path (its own
     * queue-or-sync decision) — which now lands on the new collection via
     * the alias. Models without timestamps can't be windowed; the next
     * reimport is their catch-up.
     *
     * @param  Model&SearchableModel  $model
     */
    protected function replay(Model $model, Carbon $started): int
    {
        if (! $model->usesTimestamps() || $model->getUpdatedAtColumn() === null) {
            return 0;
        }

        $replayed = 0;

        // A minute's buffer under the build start absorbs writer clock skew
        // and transactions that committed (with an earlier updated_at) after
        // the import chunk had already passed their id — replay is an
        // idempotent upsert, so the overlap costs nothing.
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
