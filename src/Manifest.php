<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class Manifest
{
    /** Keys allowed at the manifest root, outside `environments`. */
    protected const ALLOWED_ROOT_KEYS = ['name', 'timezone', 'environments'];

    /**
     * An in-memory manifest that stands in for yolo.yml when set. Default null —
     * every command reads the file from disk, unchanged. `destroy:environment`
     * hydrates this to reconstruct an environment YOLO once provisioned but whose
     * yolo.yml block `destroy:app` has since removed: the config comes from the live
     * account (STS), the AWS profile and the published env manifest in S3 instead.
     * Read-only by nature — the surgical writers (put / removeEnvironment /
     * setServiceList) read the file directly, and the hydrating command never writes.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $hydrated = null;

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
        'public-subnets',
        'internet-gateway', 'route-table', 'vpc',
        'services',
        'rds.subnet', 'rds.security-group',
        'database',
        'ecs.security-group',
        'sqs.depth-alarm-threshold', 'sqs.depth-alarm-period', 'sqs.depth-alarm-evaluation-periods',
        'cache.store',
        'session.driver',
        'task-role-policies',
        'budget', 'budget.amount', 'budget.strategy',
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
        'tasks.queue.autoscaling.*',
        'tasks.queue.cpu', 'tasks.queue.memory', 'tasks.queue.spot',
        'tasks.queue.shutdown-grace-period', 'tasks.queue.enable-execute-command',
        'tasks.scheduler',
        'tasks.scheduler.cpu', 'tasks.scheduler.memory',
        'tasks.scheduler.shutdown-grace-period', 'tasks.scheduler.enable-execute-command',
        'build', 'deploy', 'deploy-all',
    ];

    public static function exists(): bool
    {
        return static::$hydrated !== null || file_exists(Paths::manifest());
    }

    /**
     * Stand an in-memory manifest in for yolo.yml for the rest of this run — see
     * the {@see $hydrated} property. Used by `destroy:environment` to run against an
     * environment yolo.yml no longer declares.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function hydrate(array $manifest): void
    {
        static::$hydrated = $manifest;
    }

    /** Drop any hydrated manifest, falling back to yolo.yml on disk (test reset). */
    public static function flushHydration(): void
    {
        static::$hydrated = null;
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
        return static::$hydrated ?? Yaml::parse(file_get_contents(Paths::manifest()));
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
        // Scalar writes are applied surgically — the value is rewritten in place
        // (or its key, plus any missing parent blocks, spliced in) so comments,
        // blank lines, key ordering and quoting in yolo.yml all survive. Non-scalar
        // writes (init scaffolding arrays like deploy/tenants into a fresh file) and
        // any key the surgical pass can't anchor fall back to a full re-dump.
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
     * — comments, blank lines, ordering, indentation, quoting. Updates the value in
     * place when the key exists; otherwise splices the missing chain (the leaf plus
     * any absent intermediate blocks) in as a fresh block under the deepest existing
     * ancestor on the path. Returns null when no block-style ancestor can anchor it
     * — a fresh file, or a deepest ancestor that carries an inline value
     * (`web: true`, `autoscaling: {}`) and so can't take block children, where a
     * structural rewrite is needed — so the caller can fall back to a full dump.
     */
    protected static function setScalarPreservingFormat(string $raw, array $path, mixed $value): ?string
    {
        $formatted = static::formatScalar($value);
        $lines = explode("\n", $raw);

        // Walk the block structure, tracking the active key path by indentation and
        // the deepest existing ancestor of $path we could hang a missing chain off.
        $stack = [];        // list of [indent, key] for the current ancestry
        $ancestor = null;   // [depth, lineIndex, indent, blockStyle] of the deepest path-prefix found

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

            // Track the deepest line whose path is a strict prefix of $path — the
            // node we'd splice the missing chain under. Recorded regardless of style:
            // a deepest ancestor carrying an inline value (`web: true`) can't take
            // block children, and splicing under a shallower one would duplicate it,
            // so that case must bail rather than splice (handled below).
            $depth = count($currentPath);

            if ($depth < count($path) && $currentPath === array_slice($path, 0, $depth) && ($ancestor === null || $depth > $ancestor[0])) {
                $blockStyle = trim((string) preg_replace('/#.*$/', '', $matches[3])) === '';
                $ancestor = [$depth, $index, $indent, $blockStyle];
            }
        }

        // No usable ancestor, or the deepest one carries an inline value → bail so
        // put() re-dumps (which renders the whole structure as proper blocks).
        if ($ancestor === null || ! $ancestor[3]) {
            return null;
        }

        // Splice every path element below the deepest existing ancestor in as a fresh
        // block under it — the missing intermediate blocks plus the leaf, each level
        // indented two spaces deeper. With only the immediate parent missing this is a
        // single leaf line; with a gap (an absent `autoscaling`) it builds the chain.
        [$depth, $lineIndex, $indent] = $ancestor;
        $missing = array_slice($path, $depth);
        $lastOffset = count($missing) - 1;
        $insert = [];

        foreach ($missing as $offset => $key) {
            $keyIndent = str_repeat(' ', $indent + 2 * ($offset + 1));
            $insert[] = $offset === $lastOffset
                ? sprintf('%s%s: %s', $keyIndent, $key, $formatted)
                : sprintf('%s%s:', $keyIndent, $key);
        }

        array_splice($lines, $lineIndex + 1, 0, $insert);

        return implode("\n", $lines);
    }

    /**
     * Surgically rewrite this environment's app `services` claim list to $services
     * as a block sequence (`services:` with `- item` children) — preserving every
     * other byte of yolo.yml (comments, ordering, quoting), unlike the put() re-dump. Drops the
     * key for an empty list. Verifies the result parses to exactly the intended
     * services before committing, so an unanticipated layout never corrupts the
     * file: on any doubt it writes nothing and returns false, and the caller falls
     * back to telling the operator to edit by hand.
     *
     * @param  array<int, string>  $services
     */
    public static function setServiceList(array $services): bool
    {
        $services = array_values($services);
        $raw = (string) file_get_contents(Paths::manifest());

        $rewritten = static::rewriteServiceList($raw, $services);

        if ($rewritten === null) {
            return false;
        }

        // Safety net: only commit a result that parses and yields exactly the
        // intended services — never leave a corrupt manifest from an odd layout.
        try {
            $written = Arr::get(Yaml::parse($rewritten) ?? [], sprintf('environments.%s.services', Helpers::environment()), []);
        } catch (\Throwable) {
            return false;
        }

        if (array_values((array) $written) !== $services) {
            return false;
        }

        return file_put_contents(Paths::manifest(), $rewritten) !== false;
    }

    /**
     * The pure line-surgery behind setServiceList — returns the rewritten YAML, or
     * null when the `services` key (or its env block, for an insert) can't be
     * located. Replaces an existing inline or block list with a block sequence,
     * inserts the key as the env block's first child when absent, and removes the
     * key for an empty list.
     *
     * @param  array<int, string>  $services
     */
    protected static function rewriteServiceList(string $raw, array $services): ?string
    {
        $lines = explode("\n", $raw);
        $path = ['environments', Helpers::environment(), 'services'];
        $parentPath = ['environments', Helpers::environment()];
        $removing = $services === [];

        $stack = [];
        $parentLine = null;
        $parentIndent = null;

        foreach ($lines as $index => $line) {
            if (! preg_match('/^(\s*)([A-Za-z0-9_.-]+):(.*)$/', $line, $matches)) {
                continue;
            }

            $indent = strlen($matches[1]);

            while ($stack !== [] && end($stack)[0] >= $indent) {
                array_pop($stack);
            }

            $stack[] = [$indent, $matches[2]];
            $currentPath = array_map(fn (array $entry): string => $entry[1], $stack);

            if ($currentPath === $path) {
                $children = 0;

                while (isset($lines[$index + 1 + $children]) && preg_match('/^\s*-\s/', $lines[$index + 1 + $children])) {
                    $children++;
                }

                if ($removing) {
                    array_splice($lines, $index, 1 + $children);

                    return implode("\n", $lines);
                }

                preg_match('/^(\s*)[A-Za-z0-9_.-]+:\s*.*?(\s*(?:#.*)?)$/', $line, $leaf);
                $block = static::serviceListBlock($services, strlen($leaf[1]));
                $block[0] .= $leaf[2] ?? '';   // re-attach any trailing comment to the `services:` line

                array_splice($lines, $index, 1 + $children, $block);

                return implode("\n", $lines);
            }

            if ($currentPath === $parentPath && trim((string) preg_replace('/#.*$/', '', $matches[3])) === '') {
                $parentLine = $index;
                $parentIndent = $indent;
            }
        }

        if ($removing) {
            return $raw; // nothing to remove — already absent
        }

        if ($parentLine !== null) {
            array_splice($lines, $parentLine + 1, 0, static::serviceListBlock($services, $parentIndent + 2));

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Render the `services:` claim as a block sequence at the given key indent:
     *
     *     services:
     *       - typesense
     *
     * @param  array<int, string>  $services
     * @return array<int, string>
     */
    protected static function serviceListBlock(array $services, int $keyIndent): array
    {
        $block = [str_repeat(' ', $keyIndent) . 'services:'];

        foreach ($services as $service) {
            $block[] = str_repeat(' ', $keyIndent + 2) . '- ' . $service;
        }

        return $block;
    }

    /**
     * Surgically remove an environment's entire block from yolo.yml — the
     * `environments.{environment}` key and every line nested under it, the
     * trailing blank separator included — preserving every other byte (comments,
     * ordering, the sibling environments' quoting), unlike the put() re-dump.
     * The reverse of declaring a deployment target: `destroy:app` calls it as its
     * final act, once the environment's resources are gone, so yolo.yml stops
     * advertising a target that no longer exists.
     *
     * Verifies the result parses and that exactly this environment — and nothing
     * else — was dropped before committing, so an unanticipated layout never
     * corrupts the file: on any doubt it writes nothing and returns false, and
     * the caller falls back to telling the operator to edit by hand.
     */
    public static function removeEnvironment(string $environment): bool
    {
        $raw = (string) file_get_contents(Paths::manifest());

        $rewritten = static::rewriteEnvironmentRemoval($raw, $environment);

        // Safety net: only commit a result that parses and leaves exactly the
        // sibling environments standing — never a manifest mangled by an odd layout.
        try {
            $before = array_keys((array) Arr::get(Yaml::parse($raw) ?? [], 'environments', []));
            $after = array_keys((array) Arr::get(Yaml::parse($rewritten) ?? [], 'environments', []));
        } catch (\Throwable) {
            return false;
        }

        if ($after !== array_values(array_diff($before, [$environment]))) {
            return false;
        }

        return file_put_contents(Paths::manifest(), $rewritten) !== false;
    }

    /**
     * The pure line-surgery behind removeEnvironment — returns the rewritten YAML
     * with the `environments.{environment}` block (header + nested children +
     * trailing blank lines) excised, or the input unchanged when the environment
     * is already absent. The block ends at the next line indented no deeper than
     * the header (a sibling environment or a top-level key) or at end of file.
     */
    protected static function rewriteEnvironmentRemoval(string $raw, string $environment): string
    {
        $lines = explode("\n", $raw);
        $path = ['environments', $environment];

        $stack = [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/^(\s*)([A-Za-z0-9_.-]+):(.*)$/', $line, $matches)) {
                continue;
            }

            $indent = strlen($matches[1]);

            while ($stack !== [] && end($stack)[0] >= $indent) {
                array_pop($stack);
            }

            $stack[] = [$indent, $matches[2]];
            $currentPath = array_map(fn (array $entry): string => $entry[1], $stack);

            if ($currentPath !== $path) {
                continue;
            }

            // The block runs from the header to the next structural line indented
            // no deeper than it; trailing blank lines are swept with it so the
            // separator goes too, leaving the surviving siblings cleanly spaced.
            $end = $index + 1;

            while ($end < count($lines)) {
                if (trim($lines[$end]) === '') {
                    $end++;

                    continue;
                }

                if (preg_match('/^(\s*)\S/', $lines[$end], $next) && strlen($next[1]) <= $indent) {
                    break;
                }

                $end++;
            }

            array_splice($lines, $index, $end - $index);

            return implode("\n", $lines);
        }

        return $raw;
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
        return static::get('cache.store', static::hasWeb() ? 'redis' : null);
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
        return static::get('session.driver', static::hasWeb() ? 'redis' : null);
    }

    /**
     * Whether the app runs a web (Fargate) service — `tasks.web: true` or a config
     * object. Absent ⇒ build-only/worker app; `tasks.web: false` ⇒ explicitly
     * headless (no web service), same as absent. The single gate for the ALB / CDN /
     * Route 53 / web-task provisioning (the value-aware replacement for
     * `has('tasks.web')`).
     */
    public static function hasWeb(): bool
    {
        return static::has('tasks.web') && self::taskExtraction('web') !== false;
    }

    /**
     * Whether the web service is switched off explicitly (`tasks.web: false`) — a
     * headless app. Distinct from absent (also headless) only in being self-documenting.
     */
    public static function webDisabled(): bool
    {
        return static::has('tasks.web') && self::taskExtraction('web') === false;
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
     * Whether a group autoscales. Autoscaling is **bolted on by default** for an
     * enabled web or queue tier — an omitted `autoscaling` key, `autoscaling: true`,
     * or an `autoscaling: {min, max, …}` block all mean ON; only an explicit
     * `autoscaling: false` turns it off (a fixed single task). The scheduler is a
     * pinned singleton and never autoscales. This is the single gate every scaling
     * resource keys off (scalable target, concurrency/CPU/backlog policies, burst,
     * scale-to-zero) — off ⇒ those tear down or never provision.
     */
    public static function autoscales(ServerGroup $group): bool
    {
        $enabled = match ($group) {
            ServerGroup::WEB => static::hasWeb(),
            ServerGroup::QUEUE => static::hasStandaloneQueue(),
            ServerGroup::SCHEDULER => false,
        };

        return $enabled && self::autoscalingValue($group) !== false;
    }

    /**
     * Web-tier autoscaling — the common gate, kept as a named shorthand for
     * `autoscales(ServerGroup::WEB)` (most scaling code is web).
     */
    public static function isAutoscaling(): bool
    {
        return static::autoscales(ServerGroup::WEB);
    }

    /**
     * Validate and resolve a group's `autoscaling` value — the three-state knob,
     * defaulting to ON when the key is omitted on an enabled group. `true` (or an
     * omitted key) and a non-empty config object both mean ON; `false` means off.
     * An empty object (`{}`) or null hard-fails — write `true` / `false`.
     *
     * @return bool|array<string, mixed>
     */
    private static function autoscalingValue(ServerGroup $group): bool|array
    {
        $key = "tasks.{$group->value}.autoscaling";
        $value = static::get($key, true);

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        throw new IntegrityCheckException(sprintf(
            '%s must be `true`, `false`, or a non-empty config object (got %s). Omit it for autoscaling on with defaults, or set `false` for a fixed single task.',
            $key,
            json_encode($value),
        ));
    }

    /**
     * The autoscaling floor for a group. Both default to 1 (no accidental
     * scale-to-zero); web is validated ≥ 1 (it serves traffic, can't idle to zero),
     * the queue ≥ 0 (0 opts into scale-to-zero, with a 0→1 bootstrap alarm).
     */
    public static function autoscalingMin(ServerGroup $group): int
    {
        $key = "tasks.{$group->value}.autoscaling.min";
        $value = static::get($key, 1);

        return $group === ServerGroup::WEB
            ? Helpers::validatePositiveInt($value, $key)
            : Helpers::validateNonNegativeInt($value, $key);
    }

    /**
     * The autoscaling ceiling for a group — web and the queue both default to 5.
     */
    public static function autoscalingMax(ServerGroup $group): int
    {
        $key = "tasks.{$group->value}.autoscaling.max";

        return Helpers::validatePositiveInt(static::get($key, 5), $key);
    }

    /**
     * Whether the build ships YOLO's metrics-enabled Caddyfile and runs Octane against
     * it (`octane:start --caddyfile`). True only for an autoscaling web tier on Octane
     * (worker mode): FrankenPHP's worker gauges — the burst signal — exist only there,
     * and `octane:start` overwrites the CADDY_GLOBAL_OPTIONS env var, so the metrics
     * global option has to ride in a Caddyfile rather than the environment. The single
     * gate the Caddyfile generation, the `--caddyfile` flag and the build preflight all
     * key off, so they can't drift; classic mode never reaches it (burst is inert there).
     */
    public static function usesMetricsCaddyfile(): bool
    {
        return static::isAutoscaling() && static::usesOctane();
    }

    /**
     * Which container runs the queue worker, or null when no worker runs anywhere.
     * A standalone `tasks.queue` service if extracted; else the web container
     * (bundled); else null — `tasks.queue: false` (disabled) or a worker-less app
     * with no web tier to bundle into. Null ⇒ jobs run inline (QUEUE_CONNECTION=sync).
     */
    public static function queueHost(): ?ServerGroup
    {
        return match (true) {
            static::queueDisabled() => null,
            static::hasStandaloneQueue() => ServerGroup::QUEUE,
            static::hasWeb() => ServerGroup::WEB,
            default => null,
        };
    }

    /**
     * Which container runs the scheduler (supercronic + schedule:run), or null when
     * cron runs nowhere (`tasks.scheduler: false`, or no host exists): a dedicated
     * `tasks.scheduler` service if extracted, else the standalone queue if there is
     * one, else the web container. Bundled cron lands on the least request-facing
     * service that exists. Distinct from the deploy/management tier (see deployGroup),
     * which always resolves to a real group even when the scheduler is disabled.
     */
    public static function schedulerHost(): ?ServerGroup
    {
        return match (true) {
            static::schedulerDisabled() => null,
            static::hasStandaloneScheduler() => ServerGroup::SCHEDULER,
            static::hasStandaloneQueue() => ServerGroup::QUEUE,
            static::hasWeb() => ServerGroup::WEB,
            default => null,
        };
    }

    /**
     * The autoscaling/desired-count floor for the standalone queue — its
     * `autoscaling.min` (default 1). `0` opts into scale-to-zero (idle to no tasks,
     * zero cost), except when the queue also hosts the scheduler, where cron can't
     * ride a task that idles to zero — `ensureSchedulerHostNotScaleToZero` rejects an
     * explicit `0` there.
     */
    public static function queueMin(): int
    {
        return static::autoscalingMin(ServerGroup::QUEUE);
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
     * Whether the queue runs as its own ECS service (`tasks.queue: true` or a
     * config object) rather than bundled in the web container. Absent ⇒ bundled;
     * `false` ⇒ disabled (see queueDisabled), so neither counts as standalone.
     */
    public static function hasStandaloneQueue(): bool
    {
        return static::has('tasks.queue') && self::taskExtraction('queue') !== false;
    }

    /**
     * Whether the scheduler runs as its own pinned-singleton ECS service
     * (`tasks.scheduler: true` or a config object) rather than bundled in the web
     * container. Absent ⇒ bundled; `false` ⇒ disabled (see schedulerDisabled).
     */
    public static function hasStandaloneScheduler(): bool
    {
        return static::has('tasks.scheduler') && self::taskExtraction('scheduler') !== false;
    }

    /**
     * Whether the queue worker is switched off entirely (`tasks.queue: false`) —
     * it runs nowhere, neither bundled nor extracted. Jobs must run inline
     * (QUEUE_CONNECTION=sync, enforced at build).
     */
    public static function queueDisabled(): bool
    {
        return static::has('tasks.queue') && self::taskExtraction('queue') === false;
    }

    /**
     * Whether the scheduler is switched off entirely (`tasks.scheduler: false`) —
     * cron runs nowhere. Dangerous (framework + packages lean on the scheduler), so
     * sync surfaces a warning; see SyncAppCommand::schedulerAdvisory.
     */
    public static function schedulerDisabled(): bool
    {
        return static::has('tasks.scheduler') && self::taskExtraction('scheduler') === false;
    }

    /**
     * Validate and resolve a top-level task role's block value — the three-state
     * opt-in shared by `tasks.queue` and `tasks.scheduler`, mirroring the
     * boolean-or-object form of `tasks.web.ssr` (see bundles()):
     *
     *   - `true`               extract a standalone service with default sizing
     *   - non-empty config map extract a standalone service with overrides
     *   - `false`              disabled — runs nowhere
     *
     * Callers confirm presence first (has()); an absent block is the bundled
     * default, not a value. An empty block (`tasks.queue:` → null), an empty map
     * (`{}`), a list, or any non-boolean scalar hard-fails — write `true` for
     * defaults rather than leaving the value ambiguous.
     *
     * @return bool|array<string, mixed>
     */
    private static function taskExtraction(string $task): bool|array
    {
        $value = static::get("tasks.$task");

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        throw new IntegrityCheckException(sprintf(
            'tasks.%s must be `true`, `false`, or a non-empty config object (got %s). '
            . 'Write `tasks.%s: true` to extract it with default sizing, or omit it to bundle it in the web container.',
            $task,
            json_encode($value),
            $task,
        ));
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
            static::hasWeb() ? ServerGroup::WEB : null,
            static::hasStandaloneQueue() ? ServerGroup::QUEUE : null,
            static::hasStandaloneScheduler() ? ServerGroup::SCHEDULER : null,
        ]));
    }

    /**
     * The service group a one-off deploy/management task (the `deploy:` hooks,
     * e.g. migrations) templates its task definition on — the least request-facing
     * tier that exists: a dedicated scheduler if extracted, else a standalone queue,
     * else web. Unlike schedulerHost this always resolves to a real group (it picks
     * a tier to run on, independent of whether cron is enabled), so a disabled
     * scheduler still leaves migrations a home.
     */
    public static function deployGroup(): ServerGroup
    {
        return match (true) {
            static::hasStandaloneScheduler() => ServerGroup::SCHEDULER,
            static::hasStandaloneQueue() => ServerGroup::QUEUE,
            default => ServerGroup::WEB,
        };
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

    /**
     * The RDS target DECLARED by the flat `database:` manifest key. Accepts either
     * a bare RDS identifier (a plain instance, charted via DBInstanceIdentifier) or
     * a full endpoint hostname — which auto-detects an Aurora cluster
     * (`.cluster-` in the host) vs a plain instance, and skips an RDS Proxy /
     * non-RDS host. Null when nothing's declared.
     *
     * Read from the manifest, never the app's secret `.env`: every consumer (the
     * CloudWatch dashboard body, the status TUI, the audit health probe) must
     * resolve the same target under every RBAC tier, which a secret read can't
     * guarantee. The dashboard tier-parity contract leans on this directly.
     *
     * @return array{identifier: string, cluster: bool}|null
     */
    public static function rdsTarget(): ?array
    {
        $database = static::get('database');

        if (! is_string($database) || $database === '') {
            return null;
        }

        // A bare value is a plain instance identifier; a full endpoint hostname
        // self-describes its kind.
        if (! str_ends_with($database, '.rds.amazonaws.com')) {
            return ['identifier' => $database, 'cluster' => false];
        }

        // RDS Proxy endpoints don't map to a DB metric identifier.
        if (str_contains($database, '.proxy-')) {
            return null;
        }

        return [
            'identifier' => strtok($database, '.'),
            'cluster' => str_contains($database, '.cluster-'),
        ];
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

    /**
     * The YOLO-provisioned services this app consumes — bare capability names
     * (`services: [ivs]`). Service shape (sizing, versions, hosts) lives in the
     * environment manifest, never here, so apps can't declare competing
     * configuration for shared infrastructure. Entries are validated against
     * the Service enum before any command runs (ensureServicesValid).
     *
     * @return array<int, string>
     */
    public static function services(): array
    {
        $services = static::get('services', []);

        return is_array($services) && array_is_list($services) ? $services : [];
    }

    public static function usesService(Service $service): bool
    {
        return in_array($service->value, static::services(), true);
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
