<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Codinglabs\Yolo\Runtime\Search\SearchableModel;
use Codinglabs\Yolo\Runtime\Search\TypesenseClient;
use Codinglabs\Yolo\Runtime\Search\SearchableModels;
use Codinglabs\Yolo\Runtime\Search\ReimportSearchModel;
use Codinglabs\Yolo\Runtime\Search\ZeroDowntimeReimport;

/**
 * The search index is a rebuildable projection of the database, so persistence
 * is a recovery loop, not a disk. YOLO's provider schedules this every five
 * minutes itself (`yolo.search.heal` / YOLO_SEARCH_HEAL is the opt-out).
 *
 * Only the wipe signature is healed — collection gone, or empty while the
 * database plainly isn't. Partial drift is deliberately not: live churn makes
 * count-matching a false-alarm machine, and `scout:reimport` exists for the
 * deliberate rebuild. There is nothing to serve during a heal, so the
 * zero-downtime machinery would buy nothing.
 *
 * The cache lock is the command's own (on combined-services apps the scheduler
 * fires on every web task, and a manual run must not race the scheduled one).
 * Detection failures are reported and skipped — the cluster's own alarms page
 * for node health, and a key the cluster stopped honouring is `yolo sync:app`'s
 * to fix.
 */
class ScoutHealCommand extends Command
{
    /** Roomy enough for a large refill to land; short enough that a stuck one gets another push. */
    protected const int DISPATCHED_TTL_SECONDS = 3600;

    protected $signature = 'scout:heal
        {--now : Refill inline (scout:import) instead of queueing}';

    protected $description = 'Detect wiped search collections and queue their rebuild';

    public function handle(TypesenseClient $typesense): int
    {
        if (! trait_exists(SearchableModels::SEARCHABLE_TRAIT) || (array) config('scout.typesense.client-settings', []) === []) {
            $this->info('Scout/Typesense is not configured — nothing to heal.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('scout:heal', 600);

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
            // Refilling with no resolvable schema would fail every tick, forever.
            if (! $this->rebuildable($modelClass)) {
                $this->components->warn(sprintf('%s is searchable but declares no Typesense schema (scout.typesense.model-settings collection-schema, or a typesenseCollectionSchema() method) — it cannot be auto-rebuilt.', $modelClass));

                continue;
            }

            try {
                if ($this->healthy($typesense, $modelClass)) {
                    Cache::forget($this->dispatchedKey($modelClass));

                    $this->line(sprintf('<info>✓</info> %s', $modelClass));

                    continue;
                }
            } catch (Throwable $e) {
                $this->components->error(sprintf('%s: could not inspect the index — %s', $modelClass, $e->getMessage()));
                $failures++;

                continue;
            }

            $this->refill($modelClass);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The marker stops the next ticks re-queueing a refill that's still landing —
     * Scout's range jobs aren't unique, so re-dispatch doubles the queue.
     *
     * @param  class-string<Model&SearchableModel>  $modelClass
     */
    protected function refill(string $modelClass): void
    {
        if ($this->option('now')) {
            $this->components->task(sprintf('%s: refilling inline', $modelClass), function () use ($modelClass): void {
                Artisan::call('scout:import', ['model' => $modelClass]);
            });

            return;
        }

        if (Cache::get($this->dispatchedKey($modelClass)) !== null) {
            $this->line(sprintf('<comment>…</comment> %s: refill already queued — waiting for it to land', $modelClass));

            return;
        }

        // scout:queue-import refuses non-numeric keys
        if ($this->numericKey($modelClass)) {
            Artisan::call('scout:queue-import', ['model' => $modelClass]);
        } else {
            ReimportSearchModel::dispatch($modelClass);
        }

        Cache::put($this->dispatchedKey($modelClass), true, self::DISPATCHED_TTL_SECONDS);

        $this->components->warn(sprintf('%s: index missing or empty — refill queued', $modelClass));
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function numericKey(string $modelClass): bool
    {
        return (new $modelClass())->getKeyType() === 'int';
    }

    protected function dispatchedKey(string $modelClass): string
    {
        return 'scout:heal:queued:' . $modelClass;
    }

    /**
     * Mirrors {@see ZeroDowntimeReimport::schema()} and the engine's own lookup.
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
     * The database COUNT only runs when the index reads empty, so the
     * steady-state cost is one GET per model.
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
