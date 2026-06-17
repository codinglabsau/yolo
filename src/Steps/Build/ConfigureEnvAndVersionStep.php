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
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

class ConfigureEnvAndVersionStep implements Step
{
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
        $values['ASSET_URL'] = sprintf('https://%s/builds/%s', (new AssetDistribution())->domain(), $appVersion);
        $values['VITE_ASSET_URL'] = '${ASSET_URL}';

        // Fargate-sane defaults injected only when the consumer's .env doesn't
        // already set them — the app "just works" with zero config but can still
        // override.
        $defaults = [
            'AWS_DEFAULT_REGION' => Manifest::get('region'),
        ];

        // Every web app runs a queue worker somewhere — bundled in the web container
        // or its own standalone service (queue:work always runs; there's no opt-out)
        // — so wire the connection to the queue YOLO provisions for it (it owns the
        // name + URL, so the app can't point at the wrong one); the task role carries
        // the access. Solo has one queue; multitenancy resolves the per-tenant queue
        // at runtime, so SQS_QUEUE is not pinned for it. A non-web app has no worker,
        // so force `sync` rather than route to a queue nothing consumes (the framework
        // default of `database` has the same no-worker pitfall).
        if (Manifest::has('tasks.web')) {
            $defaults['QUEUE_CONNECTION'] = 'sqs';
            $defaults['SQS_PREFIX'] = sprintf('https://sqs.%s.amazonaws.com/%s', Manifest::get('region'), Aws::accountId());

            if (! Manifest::isMultitenanted()) {
                $defaults['SQS_QUEUE'] = Helpers::keyedResourceName();
            }
        } else {
            $defaults['QUEUE_CONNECTION'] = 'sync';
        }

        if (Manifest::has('bucket')) {
            $defaults['AWS_BUCKET'] = Manifest::get('bucket');
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

    protected function generateValues(array $values): string
    {
        $result = PHP_EOL . '# YOLO generated values' . PHP_EOL;

        foreach ($values as $key => $value) {
            $result .= "$key=$value" . PHP_EOL;
        }

        return $result;
    }
}
