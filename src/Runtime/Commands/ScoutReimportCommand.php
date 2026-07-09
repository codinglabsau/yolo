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
 * The deliberate rebuild: `scout:import --fresh` without the blackout.
 * Each model's collection is rebuilt beside the live one and swapped in via
 * a Typesense alias ({@see ZeroDowntimeReimport}) — exact mirror, current
 * schema applied, searches served throughout. Use it for schema changes,
 * drift repair, or anything that used to reach for `--fresh`. (The name
 * follows scout-extended's command for the same temp-index pattern.)
 *
 * With no models given, an interactive run offers a picker over the app's
 * discovered searchable models; a non-interactive run requires an explicit
 * model or `--all`, so a fat-fingered scheduler entry can't rebuild the
 * world by accident.
 *
 * Models run sequentially, smallest collection first: during each swap the
 * old and new collections coexist, so peak node memory grows by one
 * collection's index — sequencing bounds the spike, and doing the biggest
 * last means it runs with the most already-settled headroom. Typesense
 * holds indexes in RAM (~2-3× raw size); rebuilding a large collection on
 * tightly-sized nodes may need `services.typesense.memory` bumped first.
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
                // The live index is untouched until the alias swap, so a failed
                // build leaves search serving — report and stop rather than
                // carry on rebuilding siblings against a struggling cluster.
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
     * The models to rebuild: the given classes; else every searchable model
     * under `--all`; else (interactively) a picker — never an implicit
     * everything. The full set runs smallest collection first, so the
     * per-swap memory spike peaks last, when everything else has settled.
     *
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

            $discovered = array_values(multiselect(
                label: 'Which models should be rebuilt?',
                options: $discovered,
                default: $discovered,
                required: true,
                hint: 'Every rebuild swaps in beside the live index — zero search downtime.',
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
