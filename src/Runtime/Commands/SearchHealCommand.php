<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Codinglabs\Yolo\Runtime\Search\SearchableModel;
use Codinglabs\Yolo\Runtime\Search\TypesenseClient;
use Codinglabs\Yolo\Runtime\Search\SearchableModels;
use Codinglabs\Yolo\Runtime\Search\ReimportSearchModel;
use Codinglabs\Yolo\Runtime\Search\ZeroDowntimeReimport;

/**
 * The scheduled self-heal for the app's search collections: detect a wiped
 * or emptied index and queue its rebuild. The search index is a rebuildable
 * projection of the database, so persistence is a recovery loop, not a disk —
 * a full cluster loss costs one heal interval plus a reimport, unattended.
 *
 * Schedule it in the app's console kernel:
 *
 *     $schedule->command('yolo:search:heal')->everyFiveMinutes();
 *
 * A model is unhealthy when its collection (or alias) is gone, or when the
 * index sits empty while the database plainly isn't — the wipe signature.
 * Anything subtler (partial drift) is deliberately not auto-healed: live
 * churn makes count-matching a false-alarm machine, and `yolo:search:reimport`
 * exists for the deliberate rebuild.
 *
 * Self-guarded with a cache lock, NOT `onOneServer()`: on combined-services
 * apps the scheduler fires on every web task, and a wipe must trigger one
 * rebuild, not one per task. Detection failures (cluster unreachable, key no
 * longer honoured) are reported and skipped — the cluster's own alarms page
 * for node health, and a key the cluster stopped honouring is `yolo
 * sync:app`'s to fix, not ours.
 */
class SearchHealCommand extends Command
{
    protected $signature = 'yolo:search:heal
        {--now : Rebuild inline instead of dispatching queued jobs}';

    protected $description = 'Detect wiped search collections and queue their rebuild';

    public function handle(TypesenseClient $typesense): int
    {
        if (! trait_exists(SearchableModels::SEARCHABLE_TRAIT) || (array) config('scout.typesense.client-settings', []) === []) {
            $this->info('Scout/Typesense is not configured — nothing to heal.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('yolo:search:heal', 600);

        if (! $lock->get()) {
            $this->info('Another heal pass holds the lock — skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->heal($typesense);
        } finally {
            $lock->release();
        }
    }

    protected function heal(TypesenseClient $typesense): int
    {
        $failures = 0;

        foreach (SearchableModels::all() as $modelClass) {
            // A model with no resolvable schema can't be rebuilt — dispatching
            // anyway would fail the job in schema() and re-queue it every heal
            // tick forever. Warn instead; declaring the schema is the fix.
            if (! $this->rebuildable($modelClass)) {
                $this->components->warn(sprintf('%s is searchable but declares no Typesense schema (scout.typesense.model-settings collection-schema, or a typesenseCollectionSchema() method) — it cannot be auto-rebuilt.', $modelClass));

                continue;
            }

            try {
                if ($this->healthy($typesense, $modelClass)) {
                    $this->line(sprintf('<info>✓</info> %s', $modelClass));

                    continue;
                }
            } catch (Throwable $e) {
                $this->components->error(sprintf('%s: could not inspect the index — %s', $modelClass, $e->getMessage()));
                $failures++;

                continue;
            }

            if ($this->option('now')) {
                $this->components->task(sprintf('%s: rebuilding inline', $modelClass), function () use ($modelClass): void {
                    ReimportSearchModel::dispatchSync($modelClass);
                });

                continue;
            }

            ReimportSearchModel::dispatch($modelClass);

            $this->components->warn(sprintf('%s: index missing or empty — rebuild queued', $modelClass));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether a rebuild could actually resolve a schema for this model —
     * mirrors {@see ZeroDowntimeReimport::schema()}.
     */
    protected function rebuildable(string $modelClass): bool
    {
        if (method_exists($modelClass, 'typesenseCollectionSchema')) {
            return true;
        }

        $schema = config('scout.typesense.model-settings.' . $modelClass . '.collection-schema');

        return is_array($schema) && $schema !== [];
    }

    /**
     * Healthy = the searchable name resolves to a live collection that isn't
     * empty while the database has rows. The database COUNT only runs when
     * the index reads empty, so the steady-state cost is one GET per model.
     *
     * @param  class-string<Model&SearchableModel>  $modelClass
     */
    protected function healthy(TypesenseClient $typesense, string $modelClass): bool
    {
        $model = new $modelClass();

        $target = $typesense->aliasTarget($model->searchableAs()) ?? $model->searchableAs();

        $collection = $typesense->collection($target);

        if ($collection === null) {
            return false;
        }

        if ((int) ($collection['num_documents'] ?? 0) > 0) {
            return true;
        }

        return $model->newQuery()->count() === 0;
    }
}
