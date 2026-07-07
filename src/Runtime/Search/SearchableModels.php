<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Laravel\Scout\Searchable;
use Symfony\Component\Finder\Finder;
use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic discovery of the app's searchable models — the set the heal
 * and reimport commands operate over, resolved the same way every run:
 *
 * - `scout.typesense.model-settings` keys are the primary source: the
 *   Typesense Scout driver keeps each model's collection schema there, so
 *   the config already IS the searchable registry.
 * - A sweep of `app/` catches models the config missed: any concrete class
 *   whose recursive trait set includes Scout's Searchable — which also
 *   resolves an app's own wrapper trait (`class_uses_recursive` walks
 *   traits-of-traits), so a bespoke `AppSearchable` still counts.
 *
 * The union is the answer — the heal command separately checks each model
 * resolves a schema before queueing a rebuild (a swept-but-undeclared model
 * is a misconfiguration it names rather than a job it fails forever).
 */
class SearchableModels
{
    public const string SEARCHABLE_TRAIT = Searchable::class;

    /**
     * Every searchable model class, config-declared or swept.
     *
     * @return array<int, class-string<Model&SearchableModel>>
     */
    public static function all(): array
    {
        return array_values(array_unique([...static::configured(), ...static::swept()]));
    }

    /**
     * @return array<int, class-string<Model&SearchableModel>>
     */
    public static function configured(): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys((array) config('scout.typesense.model-settings', []))),
            static::isSearchableModel(...),
        ));
    }

    /**
     * Whether a class is a searchable Eloquent model — the runtime proof
     * behind the Model&SearchableModel type the discovery methods return
     * ({@see SearchableModel} is analysis-only; no model implements it).
     * Public so the commands can validate operator-supplied class names
     * against the same definition.
     *
     * @phpstan-assert-if-true class-string<Model&SearchableModel> $class
     */
    public static function isSearchableModel(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class)
            && in_array(self::SEARCHABLE_TRAIT, class_uses_recursive($class), true);
    }

    /**
     * Sweep a PSR-4 root (the app's `app/` by default) for concrete Model
     * classes whose recursive trait set includes Scout's Searchable.
     *
     * @return array<int, class-string<Model&SearchableModel>>
     */
    public static function swept(?string $path = null, ?string $namespace = null): array
    {
        $path ??= app_path();
        $namespace ??= app()->getNamespace();

        if (! trait_exists(self::SEARCHABLE_TRAIT) || ! is_dir($path)) {
            return [];
        }

        $models = [];

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $class = $namespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! static::isSearchableModel($class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }
}
