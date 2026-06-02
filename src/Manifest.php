<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class Manifest
{
    /** Keys allowed at the manifest root, outside `environments`. */
    protected const ALLOWED_ROOT_KEYS = ['name', 'timezone', 'environments'];

    /**
     * The complete set of valid environment-block keys as dot-paths — the single
     * source of truth for the manifest's shape. There is no `aws.*` namespace:
     * every key sits at the top of the environment block. A trailing `.*` allows
     * that prefix and anything beneath it (free-form subtrees: per-tenant config,
     * the tasks.web.* tree).
     *
     * @var array<int, string>
     */
    protected const ALLOWED_ENVIRONMENT_KEYS = [
        'account-id', 'region',
        'domain', 'apex', 'branch', 'tag', 'repository',
        'tenants.*',
        'bucket', 'alb', 'alb-logs-bucket', 'artefacts-bucket',
        'mediaconvert', 'public-subnets',
        'internet-gateway', 'route-table', 'vpc',
        'ivs', 'ivs.logging', 'ivs.log-retention-days',
        'rds.subnet', 'rds.security-group',
        'ecs.cluster', 'ecs.security-group',
        'sqs.depth-alarm-threshold', 'sqs.depth-alarm-period', 'sqs.depth-alarm-evaluation-periods',
        'cache.store',
        'session.driver',
        'tasks.web.*',
        'build', 'deploy', 'deploy-all',
    ];

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

    /**
     * Keys present in the manifest that aren't in the schema — or are at the
     * wrong level. Empty array means the shape is valid. Checks both the file
     * root and the current environment block.
     *
     * @return array<int, string>
     */
    public static function unknownKeys(): array
    {
        $manifest = static::current();

        $unknown = array_values(array_filter(
            array_keys($manifest),
            fn (string $key) => ! in_array($key, static::ALLOWED_ROOT_KEYS, true),
        ));

        foreach (static::flattenKeys($manifest['environments'][Helpers::environment()] ?? []) as $path) {
            if (! static::environmentKeyAllowed($path)) {
                $unknown[] = $path;
            }
        }

        return $unknown;
    }

    /**
     * Flatten an associative manifest node to leaf dot-paths. Lists and scalars
     * are leaves at their own key — we don't descend into list items or
     * free-form values.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    protected static function flattenKeys(array $node, string $prefix = ''): array
    {
        $paths = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = array_merge($paths, static::flattenKeys($value, $path));
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    protected static function environmentKeyAllowed(string $path): bool
    {
        foreach (static::ALLOWED_ENVIRONMENT_KEYS as $allowed) {
            if ($allowed === $path) {
                return true;
            }

            // `prefix.*` matches that prefix and anything beneath it. Comparing
            // against `$path.` (trailing dot) stops `tasks.web.*` matching a
            // sibling like `tasks.webhook`.
            if (str_ends_with($allowed, '.*') && str_starts_with($path . '.', substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
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

    public static function isHeadless(): bool
    {
        if (static::has('apex') || static::has('domain')) {
            return false;
        }

        // Read raw — tenants() normaliser TypeErrors on a headless tenant.
        return collect(static::get('tenants', []))
            ->every(fn (array $config) => ! isset($config['apex']) && ! isset($config['domain']));
    }

    public static function ivsEnabled(): bool
    {
        return static::get('ivs') === true
            || static::get('ivs.logging') === true;
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
                $config['apex'] = $config['apex'] ?? ($config['domain'] ?? null);

                if ($config['apex'] !== null && str_starts_with($config['apex'], 'www.')) {
                    return throw new IntegrityCheckException(sprintf("The apex record %s cannot start with 'www'.", $config['apex']));
                }

                return [$tenantId => $config];
            })->toArray();
    }
}
