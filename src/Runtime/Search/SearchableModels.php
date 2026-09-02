<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Laravel\Scout\Searchable;
use Symfony\Component\Finder\Finder;
use Illuminate\Database\Eloquent\Model;

/**
 * `scout.typesense.model-settings` keys are the primary source (the Typesense
 * driver keeps each model's schema there, so the config already IS the
 * registry); a sweep of `app/` catches what the config missed.
 * `class_uses_recursive` walks traits-of-traits, so an app's own wrapper trait
 * around Searchable still counts.
 */
class SearchableModels
{
    public const string SEARCHABLE_TRAIT = Searchable::class;

    /**
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
     * The runtime proof behind the Model&SearchableModel type ({@see SearchableModel}
     * is analysis-only; no model implements it).
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
