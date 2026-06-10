<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\ServerGroup;
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
        'bucket', 'alb',
        'mediaconvert', 'public-subnets',
        'internet-gateway', 'route-table', 'vpc',
        'ivs', 'ivs.logging', 'ivs.log-retention-days',
        'rds.subnet', 'rds.security-group',
        'ecs.security-group',
        'sqs.depth-alarm-threshold', 'sqs.depth-alarm-period', 'sqs.depth-alarm-evaluation-periods',
        'cache.store',
        'session.driver',
        'task-role-policies',
        // Each task group has a fixed, known shape, so every key is listed
        // explicitly: an unrecognised key under tasks.web / tasks.queue /
        // tasks.scheduler hard-fails rather than being silently accepted by a
        // wildcard. health-check / autoscaling / the ssr object form are the only
        // nested subtrees.
        'tasks.web',
        'tasks.web.octane',
        'tasks.web.port', 'tasks.web.cpu', 'tasks.web.memory', 'tasks.web.platform',
        'tasks.web.enable-execute-command', 'tasks.web.shutdown-grace-period',
        'tasks.web.log-retention', 'tasks.web.log-group',
        'tasks.web.ssr', 'tasks.web.ssr.*',
        'tasks.web.health-check.*', 'tasks.web.autoscaling.*',
        'tasks.queue',
        'tasks.queue.min', 'tasks.queue.max', 'tasks.queue.backlog-per-task',
        'tasks.queue.cpu', 'tasks.queue.memory', 'tasks.queue.spot',
        'tasks.queue.shutdown-grace-period', 'tasks.queue.enable-execute-command',
        'tasks.scheduler',
        'tasks.scheduler.cpu', 'tasks.scheduler.memory',
        'tasks.scheduler.shutdown-grace-period', 'tasks.scheduler.enable-execute-command',
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
            fn (string $key): bool => ! in_array($key, static::ALLOWED_ROOT_KEYS, true),
        ));

        $prefix = sprintf('environments.%s.', Helpers::environment());

        foreach (static::flattenKeys($manifest['environments'][Helpers::environment()] ?? []) as $path) {
            if (! static::environmentKeyAllowed($path)) {
                $unknown[] = $prefix . $path;
            }
        }

        return $unknown;
    }

    /**
     * Flatten an associative manifest node to leaf dot-paths. Lists and scalars
     * are leaves at their own key — we don't descend into list items or
     * free-form values. Public because EnvManifest validates its own schema
     * with the same flattening.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    public static function flattenKeys(array $node, string $prefix = ''): array
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

    public static function get(string $key, $default = null): mixed
    {
        if (! static::has($key)) {
            return $default;
        }

        return Arr::get(static::current()['environments'][Helpers::environment()], $key);
    }

    public static function put(string $key, mixed $value): false|int
    {
        // Scalar writes are applied surgically — only the changed value's
        // characters are rewritten, so comments, blank lines, key ordering and
        // quoting in yolo.yml all survive. Non-scalar writes (init scaffolding
        // arrays like deploy/tenants into a fresh file) and any key the surgical
        // pass can't locate fall back to a full re-dump.
        if (is_scalar($value)) {
            $path = [...['environments', Helpers::environment()], ...explode('.', $key)];

            $rewritten = static::setScalarPreservingFormat(file_get_contents(Paths::manifest()), $path, $value);

            if ($rewritten !== null) {
                return file_put_contents(Paths::manifest(), $rewritten);
            }
        }

        $manifest = static::current();

        Arr::set($manifest, sprintf('environments.%s.%s', Helpers::environment(), $key), $value);

        return file_put_contents(
            Paths::manifest(),
            str_replace("'", '', Yaml::dump($manifest, inline: 20, indent: 2))
        );
    }

    /**
     * Rewrite a single scalar at $path (e.g. [environments, production, tasks,
     * web, autoscaling, min]) in raw block-style YAML, preserving every other byte
     * — comments, blank lines, ordering, indentation, quoting. Updates the value
     * in place when the key exists; inserts it as the first child when only its
     * immediate parent block exists. Returns null when neither the key nor its
     * parent can be located, so the caller can fall back to a full dump.
     */
    protected static function setScalarPreservingFormat(string $raw, array $path, mixed $value): ?string
    {
        $formatted = static::formatScalar($value);
        $lines = explode("\n", $raw);

        // Walk the block structure, tracking the active key path by indentation.
        $stack = [];          // list of [indent, key] for the current ancestry
        $parentLine = null;   // line index of the immediate parent block, if present
        $parentIndent = null;
        $parentPath = array_slice($path, 0, -1);

        foreach ($lines as $index => $line) {
            if (! preg_match('/^(\s*)([A-Za-z0-9_.-]+):(.*)$/', $line, $matches)) {
                continue; // blank line, comment, list item or continuation — leave untouched
            }

            $indent = strlen($matches[1]);

            while ($stack !== [] && end($stack)[0] >= $indent) {
                array_pop($stack);
            }

            $stack[] = [$indent, $matches[2]];
            $currentPath = array_map(fn (array $entry): string => $entry[1], $stack);

            if ($currentPath === $path) {
                // Update in place: keep indent + key, the exact post-colon spacing
                // and any trailing inline comment — replace only the value.
                preg_match('/^(\s*[A-Za-z0-9_.-]+:)(\s*)(.*?)(\s*(?:#.*)?)$/', $line, $leaf);
                $lines[$index] = $leaf[1] . $leaf[2] . $formatted . $leaf[4];

                return implode("\n", $lines);
            }

            // Only a block-style parent (nothing after the colon but maybe a
            // comment) can take a new child line. A parent with an inline value —
            // `queue: {}` / `queue: []` — would be corrupted by splicing a block
            // child beneath it, so leave parentLine null and let put() fall back to
            // a full re-dump (which renders it as a proper block).
            if ($currentPath === $parentPath && trim((string) preg_replace('/#.*$/', '', $matches[3])) === '') {
                $parentLine = $index;
                $parentIndent = $indent;
            }
        }

        // Key absent but its immediate parent block exists → insert as first child.
        if ($parentLine !== null) {
            $childIndent = str_repeat(' ', $parentIndent + 2);
            array_splice($lines, $parentLine + 1, 0, sprintf('%s%s: %s', $childIndent, end($path), $formatted));

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Render a scalar as a YAML value — bare where safe, double-quoted when it
     * contains characters that would otherwise change the parse.
     */
    protected static function formatScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return preg_match('/^[A-Za-z0-9_.\/@-]+$/', (string) $value)
            ? (string) $value
            : '"' . str_replace('"', '\"', (string) $value) . '"';
    }

    public static function timezone(): string
    {
        return Arr::get(static::current(), 'timezone', 'UTC');
    }

    /**
     * The effective cache store. Web apps default to the shared Valkey cluster
     * (`redis`) — the ephemeral per-task filesystem is broken across multiple
     * Fargate tasks, so a working shared cache is the right default. Set
     * `cache.store` to opt out (`file` / `database` / `array`). Non-web apps get
     * no default.
     */
    public static function cacheStore(): ?string
    {
        return static::get('cache.store', static::has('tasks.web') ? 'redis' : null);
    }

    /**
     * The effective session driver. Web apps default to `redis` — sessions land
     * on the shared Valkey cluster (strong read-after-write consistency), on its
     * own logical database (DB 0) so they're isolated from the cache keyspace
     * (DB 1) while sharing the instance. Set `session.driver` to opt out. Non-web
     * apps have no sessions, so no default.
     */
    public static function sessionDriver(): ?string
    {
        return static::get('session.driver', static::has('tasks.web') ? 'redis' : null);
    }

    /**
     * Whether the web container bundles an opt-in in-process program — today only
     * `tasks.web.ssr` (Inertia's SSR renderer). Truthy is a bare `true` or an
     * object of overrides (e.g. shutdown-grace-period); the bare-flag form goes
     * through strict bool validation so a typo can't silently disable it.
     *
     * Queue and scheduler bundling is NOT a flag — it's derived from task presence
     * (see queueHost / schedulerHost): each rides the web container unless a
     * top-level tasks.queue / tasks.scheduler block extracts it into its own service.
     */
    public static function bundles(string $program): bool
    {
        $value = static::get("tasks.web.$program", false);

        return is_array($value) || Helpers::validateStrictBool($value, "tasks.web.$program");
    }

    /**
     * Whether the web tier runs Octane (FrankenPHP worker mode) — the default.
     * Set `tasks.web.octane: false` to run FrankenPHP in classic mode instead
     * (per-request boot, no resident app), for an app that isn't Octane-safe yet
     * — e.g. a migration onto Fargate that predates an Octane-readiness pass. Same
     * image and port either way; only the web launch command differs (see
     * ProcessCommands::web). Goes through strict bool validation so a typo can't
     * silently flip the web server.
     */
    public static function usesOctane(): bool
    {
        return Helpers::validateStrictBool(static::get('tasks.web.octane', true), 'tasks.web.octane');
    }

    /**
     * Which container runs the queue worker: a standalone `tasks.queue` service if
     * extracted, else bundled in the web container. The worker always runs
     * somewhere — there's no opt-out.
     */
    public static function queueHost(): ServerGroup
    {
        return static::hasStandaloneQueue() ? ServerGroup::QUEUE : ServerGroup::WEB;
    }

    /**
     * Which container runs the scheduler (crond + schedule:run): a dedicated
     * `tasks.scheduler` service if extracted, else the standalone queue if there is
     * one, else the web container. The cron always runs somewhere — there's no
     * opt-out — so management work lands on the least request-facing service that
     * exists. This is also the deploy/management tier (see deployGroup).
     */
    public static function schedulerHost(): ServerGroup
    {
        return match (true) {
            static::hasStandaloneScheduler() => ServerGroup::SCHEDULER,
            static::hasStandaloneQueue() => ServerGroup::QUEUE,
            default => ServerGroup::WEB,
        };
    }

    /**
     * The autoscaling/desired-count floor for the standalone queue. A scale-to-zero
     * queue (`min: 0`, the default) idles to no tasks — but when the queue also
     * hosts the scheduler (no dedicated `tasks.scheduler` service), it can't scale
     * to zero or cron would stop, so the floor defaults to 1. An explicit
     * `tasks.queue.min: 0` in that topology is rejected by the validator.
     */
    public static function queueMin(): int
    {
        $default = static::schedulerHost() === ServerGroup::QUEUE ? 1 : 0;

        return Helpers::validateNonNegativeInt(static::get('tasks.queue.min', $default), 'tasks.queue.min');
    }

    /**
     * Additional IAM policy ARNs to attach to this app's ECS task role, declared
     * under the top-level `task-role-policies` list. The role is per-app, so these
     * grant only this app's containers and never reach another app. Each entry must
     * be a customer- or AWS-managed IAM policy ARN; a malformed value hard-fails the
     * plan rather than silently dropping the grant.
     *
     * @return array<int, string>
     */
    public static function taskRolePolicies(): array
    {
        $policies = static::get('task-role-policies', []);

        if (! is_array($policies) || ! array_is_list($policies)) {
            throw new IntegrityCheckException('task-role-policies must be a list of IAM policy ARNs.');
        }

        foreach ($policies as $arn) {
            if (! is_string($arn) || ! preg_match('#^arn:aws:iam::(aws|\d{12}):policy/.+#', $arn)) {
                throw new IntegrityCheckException(sprintf(
                    'task-role-policies entries must be IAM policy ARNs (got %s).',
                    json_encode($arn),
                ));
            }
        }

        return $policies;
    }

    /**
     * Whether the queue runs as its own ECS service (a top-level `tasks.queue`
     * block) rather than bundled in the web container. Presence is the opt-in —
     * an empty block extracts the queue with default sizing and scale-to-zero.
     */
    public static function hasStandaloneQueue(): bool
    {
        return static::has('tasks.queue');
    }

    /**
     * Whether the scheduler runs as its own pinned-singleton ECS service (a
     * top-level `tasks.scheduler` block) rather than bundled in the web container.
     */
    public static function hasStandaloneScheduler(): bool
    {
        return static::has('tasks.scheduler');
    }

    /**
     * The workloads that run as their own ECS service for this app: web (when
     * there's a `tasks.web` block) plus any extracted queue/scheduler. This is the
     * single list that deploy registers task-def revisions for, sync provisions
     * services for, and `yolo run --group` fans across. Bundled queue/scheduler
     * are NOT here — they ride inside the web container, not their own service.
     *
     * @return array<int, ServerGroup>
     */
    public static function serverGroups(): array
    {
        return array_values(array_filter([
            static::has('tasks.web') ? ServerGroup::WEB : null,
            static::hasStandaloneQueue() ? ServerGroup::QUEUE : null,
            static::hasStandaloneScheduler() ? ServerGroup::SCHEDULER : null,
        ]));
    }

    /**
     * The service group a one-off deploy/management task (the `deploy:` hooks,
     * e.g. migrations) templates its task definition on. It's the same least
     * request-facing tier the scheduler rides — a dedicated scheduler if extracted,
     * else a standalone queue, else web — so the one-off lands off the request path.
     */
    public static function deployGroup(): ServerGroup
    {
        return static::schedulerHost();
    }

    public static function apex(): string
    {
        if (static::isMultitenanted()) {
            return throw new IntegrityCheckException('Cannot determine apex domain for multitenanted environments.');
        }

        $apex = static::get('apex', static::get('domain'));

        if (str_starts_with((string) $apex, 'www.')) {
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
            ->every(fn (array $config): bool => ! isset($config['apex']) && ! isset($config['domain']));
    }

    public static function ivsEnabled(): bool
    {
        if (static::get('ivs') === true) {
            return true;
        }

        return static::get('ivs.logging') === true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function tenants(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = static::get('tenants') ?? [];

        $tenants = [];

        foreach ($configured as $tenantId => $config) {
            $config['apex'] ??= $config['domain'] ?? null;

            if ($config['apex'] !== null && str_starts_with($config['apex'], 'www.')) {
                throw new IntegrityCheckException(sprintf("The apex record %s cannot start with 'www'.", $config['apex']));
            }

            $tenants[$tenantId] = $config;
        }

        return $tenants;
    }
}
