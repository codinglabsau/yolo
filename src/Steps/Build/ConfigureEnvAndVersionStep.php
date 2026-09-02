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
     * Every static key __invoke() writes — InitCommand strips these from the
     * starter env so it never carries a second copy of a build-owned value.
     * Service buildValues() keys are dynamic and deliberately not listed.
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

        foreach (Manifest::services() as $service) {
            $values = [...$values, ...Service::from($service)->definition()->buildValues()];
        }

        $values = [...$values, ...$this->envSideValues()];

        // Versioned per build so old builds' hashed bundles keep resolving. A
        // web-less app has no distribution — resolving its domain would crash
        // the build, so asset() falls back to relative URLs.
        if (Manifest::hasWeb()) {
            $values['ASSET_URL'] = sprintf('https://%s/builds/%s', (new AssetDistribution())->domain(), $appVersion);
            $values['VITE_ASSET_URL'] = '${ASSET_URL}';
        }

        // awslogs only captures stdout/stderr; the ALB terminates TLS (else
        // http:// URLs and redirect loops); the image is FrankenPHP.
        $this->enforce($envPath, $values, 'LOG_CHANNEL', 'stderr');
        $this->enforce($envPath, $values, 'OCTANE_HTTPS', 'true');
        $this->enforce($envPath, $values, 'OCTANE_SERVER', 'frankenphp');

        $defaults = [
            'AWS_DEFAULT_REGION' => Manifest::get('region'),
            'APP_ENV' => $this->environment,
        ];

        // One image serves web + queue + scheduler, so QUEUE_CONNECTION follows
        // worker presence, not web presence. With no worker anywhere, jobs would
        // pile into a queue nothing drains — force `sync`.
        if (Manifest::queueHost() instanceof ServerGroup) {
            $defaults['QUEUE_CONNECTION'] = 'sqs';
            $defaults['SQS_PREFIX'] = sprintf('https://sqs.%s.amazonaws.com/%s', Manifest::get('region'), Aws::accountId());

            if (! Manifest::fansQueuesPerTenant()) {
                // A dedicated multi-tenant app derives its per-tenant queue at runtime.
                $defaults['SQS_QUEUE'] = Helpers::defaultQueueName();
            }
        } else {
            $this->ensureSyncQueueConnection($envPath);

            $defaults['QUEUE_CONNECTION'] = 'sync';
        }

        if (Manifest::has('bucket')) {
            $defaults['AWS_BUCKET'] = Paths::s3AppBucket();
            $defaults['FILESYSTEM_DISK'] = 's3';
        }

        if ($cacheStore = Manifest::cacheStore()) {
            $defaults['CACHE_STORE'] = $cacheStore;

            if ($cacheStore === 'redis') {
                $defaults['REDIS_HOST'] = (new CacheCluster())->endpoint();
                $defaults['REDIS_PORT'] = (string) CacheCluster::PORT;

                // Enforced, not defaulted: every app in the env shares one
                // unauthenticated Valkey node, so this prefix is the only thing
                // keeping one app's keys off another's.
                $this->enforce($envPath, $values, 'REDIS_PREFIX', Helpers::keyedResourceName() . '_');
            }
        }

        // SESSION_CONNECTION is deliberately left unset: null routes the redis
        // session handler to the `default` connection (DB 0), keeping sessions
        // off the cache connection (DB 1).
        if ($sessionDriver = Manifest::sessionDriver()) {
            $defaults['SESSION_DRIVER'] = $sessionDriver;
        }

        // The render URL already defaults to 127.0.0.1:13714 in config/inertia.php.
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
     * The env-side `.env.{app}` only exists once sync has minted a secret into
     * it (e.g. a scoped TYPESENSE_API_KEY), so not-found is the steady state
     * for most apps and must never fail the build.
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
     * YOLO can't fix a non-sync override by injecting `sync` — defaults are
     * skipped once the key is set and phpdotenv is first-wins — so hard-fail.
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
