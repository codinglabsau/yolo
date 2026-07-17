<?php

namespace Codinglabs\Yolo\Steps\Build;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

class ConfigureEnvAndVersionStep implements Step
{
    /**
     * Every static key this step writes into the built env — enforced platform
     * invariants plus the manifest-derived defaults below. InitCommand strips
     * these (and anything AWS_*) from the starter env it scaffolds, so the file
     * never carries a second copy of a value the build owns; keep this list in
     * step with the keys written in __invoke(). Service buildValues() keys are
     * dynamic and deliberately not listed.
     */
    public const array INJECTED_KEYS = [
        'APP_VERSION',
        'ASSET_URL',
        'VITE_ASSET_URL',
        'LOG_CHANNEL',
        'OCTANE_HTTPS',
        'OCTANE_SERVER',
        'QUEUE_CONNECTION',
        'SQS_PREFIX',
        'SQS_QUEUE',
        'FILESYSTEM_DISK',
        'CACHE_STORE',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PREFIX',
        'SESSION_DRIVER',
        'INERTIA_SSR_ENABLED',
    ];

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        $appVersion = Arr::get($options, 'app-version');
        $envPath = Paths::build(".env.$this->environment");

        $this->filesystem->put(
            Paths::version(),
            $appVersion
        );

        $values = [
            'APP_VERSION' => $appVersion,
        ];

        // Each consumed service contributes its build-time env values — the
        // injection half of the service contract lives on the definition
        // (ServiceDefinition::buildValues()), so a new service never edits
        // this step.
        foreach (Manifest::services() as $service) {
            $values = [...$values, ...Service::from($service)->definition()->buildValues()];
        }

        // YOLO's per-app env-side secret channel: any key sync minted into this
        // app's environment-side `.env` (env/.env.{app} in the env config bucket)
        // — currently the Typesense scoped TYPESENSE_API_KEY. Merged in like a
        // buildValues entry so it's baked into the image. The file doesn't exist
        // until the key is minted (the common case on a fresh app, and for apps
        // with no env-side secret at all), so a not-found read is skipped
        // silently — never a build failure.
        $values = [...$values, ...$this->envSideValues()];

        // Assets always live in S3 behind the YOLO-provisioned CloudFront
        // distribution. ASSET_URL points app-generated asset URLs at it,
        // versioned per build so each deploy's hashed bundle sits under its
        // own prefix and old builds keep resolving. VITE_ASSET_URL references
        // it (the stock Laravel `VITE_APP_NAME="${APP_NAME}"` idiom) so the same
        // prefix reaches Vite's import.meta.env — phpdotenv resolves the
        // reference both in the build step's parse and at container runtime.
        // A web-less app serves no assets and provisions no distribution, so
        // resolving its domain here would crash the build — skip both keys and
        // let asset() fall back to relative URLs.
        if (Manifest::hasWeb()) {
            $values['ASSET_URL'] = sprintf('https://%s/builds/%s', (new AssetDistribution())->domain(), $appVersion);
            $values['VITE_ASSET_URL'] = '${ASSET_URL}';
        }

        // Platform invariants — values the YOLO image cannot run without — are
        // SET unconditionally, and a conflicting explicit value in the app's .env
        // hard-fails the build rather than shipping a silently-broken image:
        //   LOG_CHANNEL=stderr  — awslogs only captures stdout/stderr; single/daily
        //                         would write to a file nothing collects.
        //   OCTANE_HTTPS=true   — the ALB terminates TLS, so without this Octane
        //                         generates http:// URLs and redirect loops.
        //   OCTANE_SERVER=frankenphp — the image is FrankenPHP; it won't boot otherwise.
        $this->enforce($envPath, $values, 'LOG_CHANNEL', 'stderr');
        $this->enforce($envPath, $values, 'OCTANE_HTTPS', 'true');
        $this->enforce($envPath, $values, 'OCTANE_SERVER', 'frankenphp');

        // Fargate-sane defaults injected only when the consumer's .env doesn't
        // already set them — the app "just works" with zero config but can still
        // override.
        $defaults = [
            'AWS_DEFAULT_REGION' => Manifest::get('region'),
            'APP_ENV' => $this->environment,
        ];

        // QUEUE_CONNECTION is one value baked into the one image every task shares
        // (web + queue + scheduler), so it follows WORKER presence, not web presence:
        // wherever a queue:work runs — bundled in the web container, a standalone
        // tasks.queue, or a headless worker — point the connection at the SQS queue
        // YOLO provisions (it owns the name + URL so the app can't target the wrong
        // one; the task role carries access), so producers (web requests, scheduled
        // jobs) and the worker share one queue. Solo pins SQS_QUEUE; multitenancy
        // resolves the per-tenant queue at runtime, so it isn't pinned. With no worker
        // anywhere (tasks.queue: false, or a worker-less app) jobs would pile into a
        // queue nothing drains, so force `sync` (run inline at dispatch) — and ENFORCE
        // it: a non-sync override would silently break, so hard-fail rather than ship.
        if (Manifest::queueHost() instanceof ServerGroup) {
            $defaults['QUEUE_CONNECTION'] = 'sqs';
            $defaults['SQS_PREFIX'] = sprintf('https://sqs.%s.amazonaws.com/%s', Manifest::get('region'), Aws::accountId());

            if (! Manifest::isMultitenanted()) {
                $defaults['SQS_QUEUE'] = Helpers::keyedResourceName();
            }
        } else {
            $this->ensureSyncQueueConnection($envPath);

            $defaults['QUEUE_CONNECTION'] = 'sync';
        }

        if (Manifest::has('bucket')) {
            $defaults['AWS_BUCKET'] = Manifest::get('bucket');
            $defaults['FILESYSTEM_DISK'] = 's3';
        }

        // Cache store: web apps default to the shared Valkey (Manifest::cacheStore).
        // Pin CACHE_STORE; when it's redis, point the driver at the YOLO-provisioned
        // cluster (read live — synced before deploy) and isolate this app on the
        // shared node with a per-app key prefix.
        if ($cacheStore = Manifest::cacheStore()) {
            $defaults['CACHE_STORE'] = $cacheStore;

            if ($cacheStore === 'redis') {
                $defaults['REDIS_HOST'] = (new CacheCluster())->endpoint();
                $defaults['REDIS_PORT'] = (string) CacheCluster::PORT;
                $defaults['REDIS_PREFIX'] = Helpers::keyedResourceName() . '_';
            }
        }

        // Session driver: web apps default to redis (Manifest::sessionDriver).
        // Pin SESSION_DRIVER only. For redis we deliberately leave SESSION_CONNECTION
        // unset — a null connection routes Laravel's redis session handler to the
        // stock `default` connection (DB 0), keeping sessions off the cache
        // connection (DB 1). Same Valkey instance, separate keyspace. Other drivers
        // (database/cookie/file) need no extra env here.
        if ($sessionDriver = Manifest::sessionDriver()) {
            $defaults['SESSION_DRIVER'] = $sessionDriver;
        }

        // Inertia SSR: when the web container bundles the SSR Node process
        // (tasks.web.ssr), turn Inertia's SSR on so PHP renders pages through it.
        // The render URL defaults to 127.0.0.1:13714 in config/inertia.php, so only
        // the enable flag is pinned — and only if the app hasn't set it already.
        if (Manifest::bundles('ssr')) {
            $defaults['INERTIA_SSR_ENABLED'] = 'true';
        }

        foreach ($defaults as $key => $value) {
            if (! $this->envDefines($envPath, $key)) {
                $values[$key] = $value;
            }
        }

        $this->filesystem->append($envPath, $this->generateValues($values));

        return StepResult::SUCCESS;
    }

    /**
     * Parse this app's environment-side `.env` (env/.env.{app} in the env
     * config bucket) into key=>value pairs to merge into the built env. Empty
     * when the file doesn't exist yet (S3 not-found) — minting the key is what
     * creates it, so its absence is the steady state until then and must never
     * crash the build.
     *
     * @return array<string, string>
     */
    protected function envSideValues(): array
    {
        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3EnvAppEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return [];
            }

            throw $e;
        }

        return Dotenv::parse($body);
    }

    protected function envDefines(string $path, string $key): bool
    {
        if (! $this->filesystem->exists($path)) {
            return false;
        }

        return preg_match('/^' . preg_quote($key, '/') . '=/m', (string) $this->filesystem->get($path)) === 1;
    }

    /**
     * Hard-fail the build when no queue worker runs (tasks.queue: false, or a
     * worker-less app) yet the app's own .env pins QUEUE_CONNECTION to anything but
     * `sync`. With no worker, any other connection dispatches into a queue nothing
     * drains; YOLO can't fix it by injecting `sync` (its default is skipped once the
     * key is set, and phpdotenv is first-wins), so it would otherwise ship a silently
     * broken build. The usual intent is a bundled worker — point the way out.
     */
    protected function ensureSyncQueueConnection(string $envPath): void
    {
        $connection = $this->envValue($envPath, 'QUEUE_CONNECTION');

        if ($connection !== null && $connection !== 'sync') {
            throw new IntegrityCheckException(sprintf(
                'QUEUE_CONNECTION is "%s" but no queue worker runs (tasks.queue: false, or no worker tier). '
                . 'Jobs dispatched to "%s" would never be processed. Set QUEUE_CONNECTION=sync, or omit '
                . 'tasks.queue to bundle a worker in the web container.',
                $connection,
                $connection,
            ));
        }
    }

    /**
     * Inject a platform-invariant env value, hard-failing if the app's own .env
     * pins a conflicting value. These keys are non-negotiable on the YOLO image
     * (the awslogs log channel, ALB TLS termination, the FrankenPHP server), so
     * an explicit override would silently break the build — surface it loudly
     * instead. When the app sets the required value (or nothing), inject it.
     */
    protected function enforce(string $envPath, array &$values, string $key, string $required): void
    {
        $current = $this->envValue($envPath, $key);

        if ($current !== null && $current !== $required) {
            throw new IntegrityCheckException(sprintf(
                '%s must be `%s` on YOLO, but the app\'s .env sets it to `%s`. Remove the override.',
                $key,
                $required,
                $current,
            ));
        }

        $values[$key] = $required;
    }

    /**
     * The value the app's staged .env defines for $key, or null when the key is
     * absent (or the file doesn't exist yet). Surrounding quotes and whitespace are
     * stripped so the comparison is on the bare value.
     */
    protected function envValue(string $path, string $key): ?string
    {
        if (! $this->filesystem->exists($path)) {
            return null;
        }

        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', (string) $this->filesystem->get($path), $matches) === 1) {
            return trim($matches[1], " \t\"'");
        }

        return null;
    }

    protected function generateValues(array $values): string
    {
        $result = PHP_EOL . '# YOLO generated values' . PHP_EOL;

        foreach ($values as $key => $value) {
            $result .= "$key=$value" . PHP_EOL;
        }

        return $result;
    }
}
