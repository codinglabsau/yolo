<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\ServerGroup;
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

    public static function has(string $key): bool
    {
        return Arr::has(static::current()['environments'][Helpers::environment()], $key);
    }

    public static function doesntHave(string $key): bool
    {
        return ! static::has($key);
    }

    public static function get(string $key, $default = null): mixed
    {
        if (! static::has($key)) {
            return $default;
        }

        return Arr::get(static::current()['environments'][Helpers::environment()], $key);
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

    public static function timezone(): string
    {
        return Arr::get(static::current(), 'timezone', 'UTC');
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

    /**
     * @return array<int, ServerGroup>
     */
    public static function serverGroups(): array
    {
        return array_values(array_filter(
            ServerGroup::cases(),
            fn (ServerGroup $group) => static::hasServerGroup($group),
        ));
    }

    /**
     * Whether the manifest declares this server group as active —
     * i.e. it is not explicitly disabled and not excluded by `combine: true`.
     *
     * This is the intent-only check. Use it from steps that provision the
     * group itself (e.g. Stage configure steps); use {@see hasServerGroup}
     * everywhere else.
     */
    public static function declaresServerGroup(ServerGroup $group): bool
    {
        if (static::get("aws.autoscaling.{$group->value}") === false) {
            return false;
        }

        if (static::get('aws.autoscaling.combine', false) && $group !== ServerGroup::WEB) {
            return false;
        }

        return true;
    }

    /**
     * Whether this server group is declared AND has been provisioned
     * (i.e. `yolo stage` has populated its autoscaling group name).
     *
     * Single entry point for deploy-time and CI-time checks — callers
     * never need to read `aws.autoscaling.{group}` themselves.
     */
    public static function hasServerGroup(ServerGroup $group): bool
    {
        if (! static::declaresServerGroup($group)) {
            return false;
        }

        $asgName = static::get("aws.autoscaling.{$group->value}");

        return is_string($asgName) && $asgName !== '';
    }

    public static function isMultitenanted(): bool
    {
        return ! empty(static::get('tenants'));
    }

    public static function ivsEnabled(): bool
    {
        return static::get('aws.ivs') === true
            || static::get('aws.ivs.logging') === true;
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
