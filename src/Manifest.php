<?php

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Aws\Route53;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Enums\QueueIsolation;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class Manifest
{
    /** Keys allowed at the manifest root, outside `environments`. */
    protected const ALLOWED_ROOT_KEYS = ['name', 'timezone', 'environments'];

    /**
     * An in-memory manifest standing in for yolo.yml, hydrated by `destroy:environment`
     * for an environment the file no longer declares. Read-only — the surgical writers
     * read the file directly.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $hydrated = null;

    /**
     * Memoised {@see deriveApex()} results, so one run doesn't re-probe Route 53 per step.
     *
     * @var array<string, string>
     */
    protected static array $apexCache = [];

    /**
     * Memoised hosted-zone names — without it a multi-tenant app pays one ListHostedZones
     * per tenant domain, enough to trip Route 53's rate limit.
     *
     * @var array<int, string>|null
     */
    protected static ?array $hostedZoneNamesCache = null;

    /**
     * Every valid environment-block key as a dot-path, nested keys included, so a misspelt
     * key hard-fails instead of being silently ignored. The only wildcards are a mid-path `*`
     * for a tenant id and `queues.*`, which lets a map-form `queues:` reach the pointed error
     * in {@see queueTiers()}.
     *
     * @var array<int, string>
     */
    protected const ALLOWED_ENVIRONMENT_KEYS = [
        'account-id', 'region',
        'domain', 'wildcard-subdomains', 'branch', 'tag', 'repository',
        // `apex` is never declared — always derived from `domain`.
        'multitenancy.landlord.domain',
        'multitenancy.landlord.wildcard-subdomains',
        'multitenancy.queue-isolation',
        'multitenancy.tenants.*.domain',
        'multitenancy.tenants.*.wildcard-subdomains',
        'queues.*',
        'queue-visibility-timeout',
        'bucket',
        'backups',
        'backups.schedule',
        'services',
        'database',
        'cache.store',
        'session.driver',
        'task-role-policies',
        'budget', 'budget.amount', 'budget.strategy',
        'tasks.web',
        'tasks.web.octane',
        'tasks.web.cpu', 'tasks.web.memory',
        'tasks.web.enable-execute-command', 'tasks.web.shutdown-grace-period',
        'tasks.web.ssr', 'tasks.web.ssr.shutdown-grace-period',
        'tasks.web.health-check.path', 'tasks.web.health-check.interval',
        'tasks.web.health-check.timeout', 'tasks.web.health-check.healthy-threshold',
        'tasks.web.health-check.unhealthy-threshold', 'tasks.web.health-check.grace-period',
        'tasks.web.autoscaling', 'tasks.web.autoscaling.min', 'tasks.web.autoscaling.max',
        'tasks.web.autoscaling.cpu-utilization',
        'tasks.web.autoscaling.scale-out-cooldown', 'tasks.web.autoscaling.scale-in-cooldown',
        'tasks.queue',
        'tasks.queue.autoscaling', 'tasks.queue.autoscaling.min', 'tasks.queue.autoscaling.max',
        'tasks.queue.autoscaling.backlog-per-task',
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
     * @param  array<string, mixed>  $manifest
     */
    public static function hydrate(array $manifest): void
    {
        static::$hydrated = $manifest;
    }

    /** Test reset. */
    public static function flushHydration(): void
    {
        static::$hydrated = null;
        static::$apexCache = [];
        static::$hostedZoneNamesCache = null;
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
     * Lists and scalars are leaves at their own key — list items and free-form values
     * aren't descended into.
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

            // Comparing against `$path.` stops `tasks.web.*` matching a sibling like `tasks.webhook`.
            if (str_ends_with($allowed, '.*') && str_starts_with($path . '.', substr($allowed, 0, -1))) {
                return true;
            }

            if (str_contains($allowed, '.*.') && static::matchesWildcardSegment($allowed, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A mid-path `*` stands for exactly one segment. A path stopping short of the full
     * pattern matches too: a bare tenant (`acme:` with no config) flattens to
     * `multitenancy.tenants.acme`, a legitimate leaf.
     */
    protected static function matchesWildcardSegment(string $allowed, string $path): bool
    {
        $pattern = explode('.', $allowed);
        $segments = explode('.', $path);

        if (count($segments) > count($pattern)) {
            return false;
        }

        foreach ($segments as $index => $segment) {
            if ($pattern[$index] !== '*' && $pattern[$index] !== $segment) {
                return false;
            }
        }

        return true;
    }

    public static function name(): string
    {
        return Arr::get(static::current(), 'name');
    }

    public static function has(string $key): bool
    {
        return static::reader()->has($key);
    }

    public static function get(string $key, $default = null): mixed
    {
        return static::reader()->get($key, $default);
    }

    /**
     * `bucket: true` puts the bucket in YOLO's keyed namespace — created and hardened at
     * birth, then never reconciled (it holds user data). A bucket *name* is bring-your-own:
     * adopted, never created, never configured.
     */
    public static function managesAppBucket(): bool
    {
        return static::get('bucket') === true;
    }

    public static function put(string $key, mixed $value): false|int
    {
        // Scalars are rewritten in place so comments, ordering and quoting in yolo.yml
        // survive; lists go through setList(). Anything else falls back to a full re-dump,
        // which loses every comment.
        if (is_scalar($value) || $value === null) {
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
     * Rewrite one scalar in raw block-style YAML, preserving every other byte; a missing
     * key is spliced in under its deepest existing ancestor. Returns null when no
     * block-style ancestor can anchor it (a fresh file, or an ancestor carrying an inline
     * value like `web: true` that can't take block children) so put() can re-dump.
     */
    protected static function setScalarPreservingFormat(string $raw, array $path, mixed $value): ?string
    {
        $formatted = static::formatScalar($value);
        $lines = explode("\n", $raw);

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
                preg_match('/^(\s*[A-Za-z0-9_.-]+:)(\s*)(.*?)(\s*(?:#.*)?)$/', $line, $leaf);
                $lines[$index] = $leaf[1] . ($formatted === '' ? '' : $leaf[2] . $formatted) . $leaf[4];

                return implode("\n", $lines);
            }

            // A deepest ancestor carrying an inline value (`web: true`) can't take block
            // children, and splicing under a shallower one would duplicate it — so record
            // it regardless of style and bail below.
            $depth = count($currentPath);

            if ($depth < count($path) && $currentPath === array_slice($path, 0, $depth) && ($ancestor === null || $depth > $ancestor[0])) {
                $blockStyle = trim((string) preg_replace('/#.*$/', '', $matches[3])) === '';
                $ancestor = [$depth, $index, $indent, $blockStyle];
            }
        }

        if ($ancestor === null || ! $ancestor[3]) {
            return null;
        }

        [$depth, $lineIndex, $indent] = $ancestor;
        $missing = array_slice($path, $depth);
        $lastOffset = count($missing) - 1;
        $insert = [];

        foreach ($missing as $offset => $key) {
            $keyIndent = str_repeat(' ', $indent + 2 * ($offset + 1));
            $insert[] = $offset === $lastOffset && $formatted !== ''
                ? sprintf('%s%s: %s', $keyIndent, $key, $formatted)
                : sprintf('%s%s:', $keyIndent, $key);
        }

        array_splice($lines, $lineIndex + 1, 0, $insert);

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function setServiceList(array $services): bool
    {
        return static::setList('services', $services);
    }

    /**
     * Rewrite a top-level environment list key as a block sequence, preserving every other
     * byte of yolo.yml. An empty list drops the key. Commits only a result that parses back
     * to exactly the intended items — on any doubt it writes nothing and returns false.
     *
     * @param  array<int, string>  $items
     */
    public static function setList(string $key, array $items): bool
    {
        $items = array_values($items);
        $raw = (string) file_get_contents(Paths::manifest());

        $rewritten = static::rewriteList($raw, $key, $items);

        if ($rewritten === null) {
            return false;
        }

        try {
            $written = Arr::get(Yaml::parse($rewritten) ?? [], sprintf('environments.%s.%s', Helpers::environment(), $key), []);
        } catch (\Throwable) {
            return false;
        }

        if (array_values((array) $written) !== $items) {
            return false;
        }

        return file_put_contents(Paths::manifest(), $rewritten) !== false;
    }

    /**
     * Returns null when the key (or its env block, for an insert) can't be located.
     *
     * @param  array<int, string>  $items
     */
    protected static function rewriteList(string $raw, string $key, array $items): ?string
    {
        $lines = explode("\n", $raw);
        $path = ['environments', Helpers::environment(), $key];
        $parentPath = ['environments', Helpers::environment()];
        $removing = $items === [];

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
                $block = static::listBlock($key, $items, strlen($leaf[1]));
                $block[0] .= $leaf[2] ?? '';   // re-attach any trailing comment to the key line

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
            array_splice($lines, $parentLine + 1, 0, static::listBlock($key, $items, $parentIndent + 2));

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    protected static function listBlock(string $key, array $items, int $keyIndent): array
    {
        $block = [str_repeat(' ', $keyIndent) . $key . ':'];

        foreach ($items as $item) {
            $block[] = str_repeat(' ', $keyIndent + 2) . '- ' . static::formatListItem($item);
        }

        return $block;
    }

    /**
     * Plain scalar where the spec allows it, so a shell command stays readable; double-quoted
     * only where plain would parse differently (leading indicator, `: ` or ` #`, edge
     * whitespace, a non-string reading). setList's parse-and-compare catches any misjudgement.
     */
    protected static function formatListItem(string $item): string
    {
        $plain = $item !== ''
            && trim($item) === $item
            && preg_match('/^[A-Za-z0-9_\/.]/', $item) === 1
            && preg_match('/(: | #)/', $item) !== 1
            && ! is_numeric($item)
            && ! in_array(strtolower($item), ['true', 'false', 'null', '~', 'yes', 'no', 'on', 'off'], true);

        return $plain ? $item : '"' . str_replace('"', '\\"', $item) . '"';
    }

    /**
     * Excise the `environments.{environment}` block from yolo.yml, preserving every other
     * byte — `destroy:app`'s final act, so the file stops advertising a target that no longer
     * exists. Commits only a result that parses and drops exactly this environment; on any
     * doubt it writes nothing and returns false.
     */
    public static function removeEnvironment(string $environment): bool
    {
        $raw = (string) file_get_contents(Paths::manifest());

        $rewritten = static::rewriteEnvironmentRemoval($raw, $environment);

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
     * The block ends at the next line indented no deeper than its header, or end of file;
     * trailing blank lines go with it so the surviving siblings stay cleanly spaced.
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

    /** Null renders as a bare `key:` line, which YAML reads back as null. */
    protected static function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

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
     * Any app that runs tasks defaults to the shared Valkey cluster: the per-task filesystem
     * is broken across Fargate tasks, and web-less workers need a shared cache just as much
     * (atomic locks, rate limiters, `onOneServer`). Build-only apps run no containers, so no default.
     */
    public static function cacheStore(): ?string
    {
        return static::get('cache.store', static::serverGroups() !== [] ? 'redis' : null);
    }

    /**
     * Web apps default to `redis`, on a separate logical database (DB 0) from the cache
     * keyspace (DB 1). Non-web apps have no sessions, so no default.
     */
    public static function sessionDriver(): ?string
    {
        return static::get('session.driver', static::hasWeb() ? 'redis' : null);
    }

    /**
     * `tasks.web: true` or a config object. Absent or `false` is a web-less app (a standalone
     * worker, or build-only). The single gate for ALB / CDN / Route 53 / web-task provisioning.
     */
    public static function hasWeb(): bool
    {
        return static::has('tasks.web') && self::taskExtraction('web') !== false;
    }

    /** Explicit `tasks.web: false` — web-less like absent, but self-documenting. */
    public static function webDisabled(): bool
    {
        return static::has('tasks.web') && self::taskExtraction('web') === false;
    }

    /**
     * An opt-in in-process web-container program — today only `tasks.web.ssr`. `true` or an
     * object of overrides; the bare flag is strict-bool validated so a typo can't silently
     * disable it. Queue and scheduler bundling is derived from task presence (queueHost /
     * schedulerHost), never a flag.
     */
    public static function bundles(string $program): bool
    {
        $value = static::get("tasks.web.$program", false);

        return is_array($value) || Helpers::validateStrictBool($value, "tasks.web.$program");
    }

    /**
     * Default on. `tasks.web.octane: false` runs FrankenPHP in classic mode (per-request boot)
     * for an app that isn't Octane-safe yet; same image and port, only the launch command
     * differs (ProcessCommands::web). Strict-bool so a typo can't silently flip the server.
     */
    public static function usesOctane(): bool
    {
        return Helpers::validateStrictBool(static::get('tasks.web.octane', true), 'tasks.web.octane');
    }

    /**
     * Autoscaling is on by default for an enabled web or queue tier — omitted, `true` or a
     * config block all mean ON; only an explicit `false` gives a fixed single task. The
     * scheduler is a pinned singleton and never autoscales. Every scaling resource keys off
     * this — off ⇒ they tear down or never provision.
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

    public static function isAutoscaling(): bool
    {
        return static::autoscales(ServerGroup::WEB);
    }

    /**
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
     * Web must be ≥ 1 (it serves traffic); the queue may be 0, opting into scale-to-zero
     * with a 0→1 bootstrap alarm.
     */
    public static function autoscalingMin(ServerGroup $group): int
    {
        $key = "tasks.{$group->value}.autoscaling.min";
        $value = static::get($key, 1);

        return $group === ServerGroup::WEB
            ? Helpers::validatePositiveInt($value, $key)
            : Helpers::validateNonNegativeInt($value, $key);
    }

    public static function autoscalingMax(ServerGroup $group): int
    {
        $key = "tasks.{$group->value}.autoscaling.max";

        return Helpers::validatePositiveInt(static::get($key, 5), $key);
    }

    /**
     * True only for an autoscaling web tier on Octane: FrankenPHP's worker gauges (the burst
     * signal) exist only in worker mode, and `octane:start` overwrites CADDY_GLOBAL_OPTIONS,
     * so the metrics option has to ride in a Caddyfile. The single gate for Caddyfile
     * generation, the `--caddyfile` flag and the build preflight.
     */
    public static function usesMetricsCaddyfile(): bool
    {
        return static::isAutoscaling() && static::usesOctane();
    }

    /**
     * Null ⇒ no worker runs anywhere (`tasks.queue: false`, or no web tier to bundle into)
     * and jobs run inline (QUEUE_CONNECTION=sync).
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
     * Bundled cron lands on the least request-facing service that exists; null when cron runs
     * nowhere. Unlike deployGroup, which always resolves a tier.
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
     * Opt-in via `backups: true` (or a `backups:` map). Backups ride the scheduler's crontab,
     * so an app with cron switched off has no host to run them.
     */
    public static function backsUpDatabases(): bool
    {
        return (bool) static::get('backups', false)
            && static::schedulerHost() instanceof ServerGroup;
    }

    /**
     * 5-field cron in the manifest timezone; the 05:00 default is off-peak with the night's
     * data fresh. Shape check only (five fields, cron charset) — supercronic is the parser of record.
     */
    public static function backupSchedule(): string
    {
        $schedule = static::get('backups.schedule', '0 5 * * *');

        $fields = is_string($schedule) ? preg_split('/\s+/', trim($schedule)) : [];

        if (count($fields) !== 5 || array_filter($fields, fn (string $field): bool => in_array(preg_match('#^[0-9*,/-]+$#', $field), [0, false], true)) !== []) {
            throw new IntegrityCheckException(sprintf(
                'backups.schedule must be a 5-field cron expression (e.g. "0 5 * * *" or "0 */4 * * *"), got "%s".',
                is_scalar($schedule) ? $schedule : gettype($schedule),
            ));
        }

        return implode(' ', $fields);
    }

    /**
     * `0` opts into scale-to-zero — except when the queue also hosts the scheduler, where
     * cron can't ride a task that idles to zero (ensureSchedulerHostNotScaleToZero rejects it).
     */
    public static function queueMin(): int
    {
        return static::autoscalingMin(ServerGroup::QUEUE);
    }

    /**
     * Extra IAM policy ARNs for this app's (per-app) ECS task role, so they never reach
     * another app. A malformed value hard-fails rather than silently dropping the grant.
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

    /** `tasks.queue: true` or a config object. Absent ⇒ bundled in web; `false` ⇒ disabled. */
    public static function hasStandaloneQueue(): bool
    {
        return static::has('tasks.queue') && self::taskExtraction('queue') !== false;
    }

    /** `tasks.scheduler: true` or a config object. Absent ⇒ bundled in web; `false` ⇒ disabled. */
    public static function hasStandaloneScheduler(): bool
    {
        return static::has('tasks.scheduler') && self::taskExtraction('scheduler') !== false;
    }

    /** `tasks.queue: false` — runs nowhere; jobs must run inline (enforced at build). */
    public static function queueDisabled(): bool
    {
        return static::has('tasks.queue') && self::taskExtraction('queue') === false;
    }

    /**
     * `tasks.scheduler: false` — cron runs nowhere. Dangerous (framework and packages lean
     * on the scheduler), so sync warns; see SyncAppCommand::schedulerAdvisory.
     */
    public static function schedulerDisabled(): bool
    {
        return static::has('tasks.scheduler') && self::taskExtraction('scheduler') === false;
    }

    /**
     * The three-state block value shared by `tasks.queue` and `tasks.scheduler`: `true`
     * (extract with defaults), a non-empty map (extract with overrides), `false` (disabled).
     * Callers check has() first — absence is the bundled default, not a value. An empty
     * block, `{}`, a list or a non-boolean scalar hard-fails rather than being read ambiguously.
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
     * The workloads that run as their own ECS service — what deploy registers task-defs
     * for, sync provisions, and `yolo run --group` fans across. A bundled queue/scheduler
     * rides inside the web container and is NOT here.
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
     * The tier a one-off deploy task (migrations) templates on — the least request-facing
     * that exists. Always resolves, unlike schedulerHost, so a disabled scheduler still
     * leaves migrations a home.
     */
    public static function deployGroup(): ServerGroup
    {
        return match (true) {
            static::hasStandaloneScheduler() => ServerGroup::SCHEDULER,
            static::hasStandaloneQueue() => ServerGroup::QUEUE,
            default => ServerGroup::WEB,
        };
    }

    /**
     * A solo app declares `domain` at the environment root; a multi-tenant app declares its
     * landlord's host inside `multitenancy`. A root `domain` is refused there because it
     * would mean both "where the landlord is served" and "what tenants hang off", which
     * separate the moment one tenant takes a custom domain.
     */
    public static function domain(): ?string
    {
        $domain = static::isMultitenanted()
            ? static::get('multitenancy.landlord.domain')
            : static::get('domain');

        return $domain === null ? null : (string) $domain;
    }

    public static function hasDomain(): bool
    {
        return static::domain() !== null;
    }

    /**
     * A tenanted app has an apex whenever its landlord declares a domain; only an app with no
     * domain at all has none — there the apex is per tenant, {@see tenants()}.
     */
    public static function apex(): string
    {
        $domain = static::domain();

        if ($domain === null) {
            return throw new IntegrityCheckException('Cannot determine apex domain: no `domain` is declared, so this app has no apex of its own.');
        }

        return static::deriveApex($domain);
    }

    /**
     * True when this is the app's domain, or the app wildcards its subdomains and this sits
     * exactly one label below. This is what lets tenants compose with `wildcard-subdomains`:
     * a tenant under the app's domain needs no zone, certificate, SNI attachment or listener
     * rule of its own, while a tenant on a custom domain gets all four.
     */
    public static function servesDomain(string $domain): bool
    {
        $appDomain = static::domain();

        if ($appDomain === null) {
            return false;
        }

        if ($domain === $appDomain) {
            return true;
        }

        if (! static::servesWildcardSubdomains() || ! str_ends_with($domain, ".$appDomain")) {
            return false;
        }

        // One label only — ACM and ALB host wildcards both match a single label,
        // so `*.{domain}` covers `tenant.{domain}` but never `a.b.{domain}`.
        return ! str_contains(substr($domain, 0, -strlen($appDomain) - 1), '.');
    }

    /**
     * One wildcard listener-rule host and one `*.{domain}` alias record instead of a resource
     * per subdomain, so a tenant can go live on a database insert. Opt-in because a wildcard
     * is only safe beneath the app's OWN domain: apps commonly share one apex, and a wildcard
     * there would let whichever app won ALB rule priority swallow its siblings' traffic.
     */
    public static function servesWildcardSubdomains(): bool
    {
        return (bool) static::get(
            static::isMultitenanted() ? 'multitenancy.landlord.wildcard-subdomains' : 'wildcard-subdomains',
            false,
        );
    }

    /** One label deep — ACM and ALB host wildcards both match a single label. */
    public static function wildcardHost(): ?string
    {
        return static::servesWildcardSubdomains()
            ? '*.' . static::domain()
            : null;
    }

    /**
     * Normally the apex, so one certificate serves every sibling app on the zone. A
     * wildcard-subdomain app needs it one level deeper: `*.example.com` matches
     * `app.example.com` but NOT `tenant.app.example.com`.
     */
    public static function certificateDomain(): string
    {
        return static::servesWildcardSubdomains()
            ? (string) static::domain()
            : static::apex();
    }

    /**
     * The longest suffix that already has a Route 53 hosted zone, so `app.example.com`
     * resolves to an existing `example.com` zone with no explicit key. With no ancestor zone
     * the domain itself is the apex (sync creates the zone), with any leading `www.` stripped.
     */
    public static function deriveApex(string $domain): string
    {
        return static::$apexCache[$domain] ??= static::resolveApex($domain);
    }

    protected static function resolveApex(string $domain): string
    {
        $zones = static::$hostedZoneNamesCache ??= Route53::hostedZoneNames();
        $labels = explode('.', $domain);

        for ($i = 0, $count = count($labels); $i < $count - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (in_array($candidate, $zones, true)) {
                return $candidate;
            }
        }

        return (string) preg_replace('/^www\./', '', $domain);
    }

    /**
     * The bare DBInstanceIdentifier / DBClusterIdentifier, never an endpoint; instance vs
     * cluster is resolved live by {@see Rds::target()}. Read from the manifest, never the
     * secret `.env`, so every consumer (dashboard body, status TUI, audit probe) resolves
     * the same target under every RBAC tier — the dashboard tier-parity contract depends on it.
     */
    public static function database(): ?string
    {
        $database = static::get('database');

        if (! is_string($database) || $database === '') {
            return null;
        }

        // RDS identifiers can't contain dots, so a dotted value is an endpoint hostname.
        if (str_contains($database, '.')) {
            throw new IntegrityCheckException(sprintf(
                'The manifest `database:` key takes the bare database name (its DBInstanceIdentifier or DBClusterIdentifier), not an endpoint hostname — got "%s".',
                $database,
            ));
        }

        return $database;
    }

    /**
     * The **mode** predicate — deliberately independent of tenants being declared: the
     * landlord block is where a multi-tenant app's host lives, so gating on tenants would
     * silently deploy a landlord-only manifest as a headless worker. Fan-out gates use
     * {@see hasTenants()}.
     */
    public static function isMultitenanted(): bool
    {
        return static::has('multitenancy');
    }

    /**
     * The fan-out predicate: per-tenant queues, DNS/TLS and teardown key off this, so a
     * landlord-only app provisions exactly what the solo shape does.
     */
    public static function hasTenants(): bool
    {
        return ! empty(static::get('multitenancy.tenants'));
    }

    /**
     * `shared` (default: one queue set for all tenants) or `dedicated` (a queue set and
     * worker program per tenant). Meaningless for a solo app (ensureQueueIsolationValid).
     */
    public static function queueIsolation(): QueueIsolation
    {
        $value = static::get('multitenancy.queue-isolation', QueueIsolation::Shared->value);

        return QueueIsolation::tryFrom($value) ?? throw new IntegrityCheckException(sprintf(
            'Unknown queue-isolation "%s" — expected "shared" or "dedicated".',
            $value,
        ));
    }

    /**
     * Gated on {@see hasTenants()}, not the mode: `dedicated` with no tenants would fan out
     * to a lone `queue_landlord` program, renaming a landlord-only app's queues for nothing.
     */
    public static function fansQueuesPerTenant(): bool
    {
        return static::hasTenants() && static::queueIsolation() === QueueIsolation::Dedicated;
    }

    public static function isHeadless(): bool
    {
        if (static::hasDomain()) {
            return false;
        }

        // Raw read: tenants() derives each apex via Route 53, an AWS round-trip this
        // validation-time predicate must not need.
        return collect(static::get('multitenancy.tenants') ?? [])
            ->every(fn (?array $config): bool => ! isset($config['domain']));
    }

    /**
     * Bare capability names (`services: [ivs]`). Service shape lives in the environment
     * manifest, so apps can't declare competing configuration for shared infrastructure.
     *
     * @return array<int, string>
     */
    public static function services(): array
    {
        return static::reader()->services();
    }

    public static function usesService(Service $service): bool
    {
        return in_array($service->value, static::services(), true);
    }

    protected static function reader(): ManifestReader
    {
        return new ManifestReader(static::current(), Helpers::environment());
    }

    /**
     * Raw read, not via {@see tenants()} — that normaliser pays a Route 53 round-trip per tenant.
     *
     * @return array<int, string>
     */
    public static function tenantDomains(): array
    {
        return collect(static::get('multitenancy.tenants') ?? [])
            ->map(fn (?array $config): ?string => $config['domain'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function tenants(): array
    {
        /** @var array<string, array<string, mixed>|null> $configured */
        $configured = static::get('multitenancy.tenants') ?? [];

        $tenants = [];

        foreach ($configured as $tenantId => $config) {
            // A bare tenant (`acme:`) parses as null — normalise so readers don't TypeError.
            $config ??= [];

            if (isset($config['domain'])) {
                $config['apex'] = static::deriveApex($config['domain']);

                // A wildcarded tenant moves its certificate off the apex onto the domain,
                // exactly as the app does (an apex cert's `*.{apex}` doesn't reach `x.{sub}.{apex}`).
                $wildcarded = (bool) ($config['wildcard-subdomains'] ?? false);

                $config['certificate-domain'] = $wildcarded ? $config['domain'] : $config['apex'];
                $config['wildcard-host'] = $wildcarded ? '*.' . $config['domain'] : null;
            }

            $tenants[(string) $tenantId] = $config;
        }

        return $tenants;
    }

    /**
     * Tier names under `queues:`, in strict-priority drain order (a leading `high` polls to
     * empty before the next). Absent ⇒ `[]`: a single queue at the app name. Names only —
     * visibility timeout is app-wide ({@see queueVisibilityTimeout}) and no per-tier knob has
     * a consumer, so the map form is rejected; when one lands it joins the list form rather
     * than replacing it.
     *
     * @return array<int, string>
     */
    public static function queueTiers(): array
    {
        $queues = static::get('queues');

        if (! is_array($queues) || $queues === []) {
            return [];
        }

        if (! array_is_list($queues)) {
            throw new IntegrityCheckException(
                'The manifest `queues:` block must be a list of tier names in priority '
                . "order (e.g. `queues:\n  - high\n  - default`). Per-queue configuration "
                . 'is not supported yet, so the map form is rejected.',
            );
        }

        return array_map(static fn ($tier): string => (string) $tier, $queues);
    }

    /**
     * SQS VisibilityTimeout for every queue the app provisions. App-wide, not per-tier: every
     * tier drains through one worker command with one job timeout. The default clears the
     * worker's 60s job timeout with margin — a shorter visibility re-delivers a still-running
     * job, so it runs twice. Raise it with the app's longest job.
     */
    public static function queueVisibilityTimeout(): int
    {
        $seconds = Helpers::validatePositiveInt(
            static::get('queue-visibility-timeout', 90),
            'queue-visibility-timeout',
        );

        if ($seconds > 43200) {
            throw new IntegrityCheckException(
                'queue-visibility-timeout must be at most 43200 (12 hours, the SQS maximum)',
            );
        }

        return $seconds;
    }
}
