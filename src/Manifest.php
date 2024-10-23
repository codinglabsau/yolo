<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

class Manifest
{
    public static function exists(): bool
    {
        return file_exists(Paths::manifest());
    }

    public static function environmentExists(string $environment): bool
    {
        if (! static::exists()) {
            return false;
        }

        return array_key_exists($environment, static::current()['environments']);
    }

    public static function current(): array
    {
        return Yaml::parse(file_get_contents(Paths::manifest()));
    }

    public static function name(): string
    {
        return Arr::get(static::current(), 'name');
    }

    public static function get(string $key): string|array|null
    {
        return Arr::get(static::current()['environments'][Helpers::environment()], $key) ?? null;
    }

    public static function put(string $key, mixed $value): false|int
    {
        $manifest = static::current();

        Arr::set($manifest, sprintf("environments.%s.%s", Helpers::environment(), $key), $value);

        return file_put_contents(
            Paths::manifest(),
            str_replace("'", '', Yaml::dump($manifest, inline: 20, indent: 2))
        );
    }

    public static function isMultitenanted(): bool
    {
        return ! empty(static::get('tenants'));
    }

    /**
     * @return array<int, array{
     *     domain: string,
     *     apex: string,
     *     subdomain: bool,
     *     www: bool
     * }>
     */
    public static function tenants(): array
    {
        return collect(static::get('tenants'))
            ->mapWithKeys(function (array $config, string $tenantId) {
                // normalise tenant config
                $config['subdomain'] = array_key_exists('apex', $config);
                $config['apex'] = $config['apex'] ?? $config['domain'];
                $config['www'] = array_key_exists('www', $config) && $config['www'];

                return [$tenantId => $config];
            })->toArray();
    }
}
