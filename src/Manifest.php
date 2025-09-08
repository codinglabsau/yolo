<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class Manifest
{
    public static function exists(): bool
    {
        return file_exists(Paths::manifest());
    }

    public static function environments(): array
    {
        return array_keys(static::current()['environments']);
    }

    public static function environmentExists(string $environment): bool
    {
        if (! static::exists()) {
            return false;
        }

        return in_array($environment, static::environments());
    }

    public static function current(): array
    {
        return Yaml::parse(file_get_contents(Paths::manifest()));
    }

    public static function name(): string
    {
        return Arr::get(static::current(), 'name');
    }

    public static function get(string $key, $default = null): mixed
    {
        return Arr::get(static::current()['environments'][Helpers::environment()], $key) ?? $default;
    }

    public static function put(string $key, mixed $value): false|int
    {
        $manifest = static::current();

        Arr::set($manifest, sprintf('environments.%s.%s', Helpers::environment(), $key), $value);

        return file_put_contents(
            Paths::manifest(),
            str_replace("'", '', Yaml::dump($manifest, inline: 20, indent: 2))
        );
    }

    public static function apex(): string
    {
        if (static::isMultitenanted()) {
            return throw new IntegrityCheckException('Cannot determine apex domain for multitenanted environments.');
        }

        // prefer the apex key when specified
        $apex = static::get('apex', static::get('domain'));

        if (str_starts_with($apex, 'www.')) {
            return throw new IntegrityCheckException(sprintf("The apex record %s cannot start with 'www'.", $apex));
        }

        return $apex;
    }

    public static function isMultitenanted(): bool
    {
        return ! empty(static::get('tenants'));
    }

    /**
     * @return array<int, array{
     *     domain: string,
     *     apex: string,
     *     www: bool
     * }>
     */
    public static function tenants(): array
    {
        return collect(static::get('tenants'))
            ->mapWithKeys(function (array $config, string $tenantId) {
                // normalise tenant config
                $config['apex'] = $config['apex'] ?? $config['domain'];

                if (str_starts_with($config['apex'], 'www.')) {
                    return throw new IntegrityCheckException(sprintf("The apex record %s cannot start with 'www'.", $config['apex']));
                }

                return [$tenantId => $config];
            })->toArray();
    }
}
