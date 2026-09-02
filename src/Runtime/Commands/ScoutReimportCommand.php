<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Codinglabs\Yolo\Runtime\Search\SearchableModel;
use Codinglabs\Yolo\Runtime\Search\TypesenseClient;
use Codinglabs\Yolo\Runtime\Search\SearchableModels;
use Codinglabs\Yolo\Runtime\Search\ZeroDowntimeReimport;

use function Laravel\Prompts\multiselect;

/**
 * `scout:import --fresh` without the blackout ({@see ZeroDowntimeReimport}); the
 * name follows scout-extended's command for the same pattern. A non-interactive
 * run requires an explicit model or `--all`, so a fat-fingered scheduler entry
 * can't rebuild the world by accident.
 *
 * Models run sequentially, smallest first: during each swap the old and new
 * collections coexist, so peak node memory grows by one collection's index —
 * doing the biggest last gives it the most settled headroom. Typesense holds
 * indexes in RAM (~2-3× raw size); a large collection on tightly-sized nodes may
 * need `services.typesense.memory` bumped first.
 */
class ScoutReimportCommand extends Command
{
    protected $signature = 'scout:reimport
        {model?* : Model classes to rebuild}
        {--all : Rebuild every searchable model}';

    protected $description = 'Rebuild search collections with zero downtime (temporary collection + alias swap)';

    public function handle(TypesenseClient $typesense): int
    {
        if (! trait_exists(SearchableModels::SEARCHABLE_TRAIT) || (array) config('scout.typesense.client-settings', []) === []) {
            $this->components->error('Scout/Typesense is not configured for this app.');

            return self::FAILURE;
        }

        $models = $this->targets();

        if ($models === []) {
            return self::FAILURE;
        }

        $reimport = new ZeroDowntimeReimport($typesense);

        foreach ($models as $modelClass) {
            $this->components->info(sprintf('Rebuilding %s', $modelClass));

            try {
                $result = $reimport->reimport($modelClass, fn (string $message) => $this->line("  {$message}"));
            } catch (Throwable $e) {
                // Search is still serving off the old index; stop rather than
                // rebuild siblings against a struggling cluster.
                $this->components->error(sprintf('%s: %s', $modelClass, $e->getMessage()));

                return self::FAILURE;
            }

            $this->components->twoColumnDetail(
                $result['alias'],
                sprintf('%d documents → %s (%d replayed)', $result['documents'], $result['collection'], $result['replayed']),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, class-string<Model&SearchableModel>>
     */
    protected function targets(): array
    {
        /** @var array<int, string> $given */
        $given = (array) $this->argument('model');

        if ($given !== []) {
            $targets = [];

            foreach ($given as $class) {
                if (! SearchableModels::isSearchableModel($class)) {
                    $this->components->error(sprintf('%s is not a searchable model this app knows.', $class));

                    return [];
                }

                $targets[] = $class;
            }

            return $targets;
        }

        $discovered = SearchableModels::all();

        if ($discovered === []) {
            $this->components->error('No searchable models discovered.');

            return [];
        }

        if (! $this->option('all')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('No models given — name them, or pass --all to rebuild every searchable model.');

                return [];
            }

            // Nothing pre-selected on purpose: a shell without a real TTY silently
            // resolves the DEFAULT, so a pre-selected-everything default would
            // rebuild every collection. --all is the explicit everything.
            $discovered = array_values(multiselect(
                label: 'Which models should be rebuilt?',
                options: $discovered,
                required: 'Select at least one model (space selects, enter confirms) — or run with --all.',
                hint: 'Space selects, enter confirms. Every rebuild swaps in beside the live index — zero search downtime.',
            ));
        }

        return $this->smallestFirst($discovered);
    }

    /**
     * One COUNT per model, not one per sort comparison — these are the
     * multi-million-row tables the command exists for.
     *
     * @param  array<int, class-string<Model&SearchableModel>>  $models
     * @return array<int, class-string<Model&SearchableModel>>
     */
    protected function smallestFirst(array $models): array
    {
        $counts = [];

        foreach ($models as $modelClass) {
            $counts[$modelClass] = (new $modelClass())->newQuery()->count();
        }

        asort($counts);

        return array_keys($counts);
    }
}
