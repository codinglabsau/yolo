<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Codinglabs\Yolo\Concerns\HasAfterCallbacks;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\error;

abstract class Command extends SymfonyCommand
{
    use ChecksIfCommandsShouldBeRunning;
    use HasAfterCallbacks;
    use RegistersAws;

    public InputInterface $input;

    public OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Helpers::app()->instance('input', $this->input = $input);
        Helpers::app()->instance('output', $this->output = $output);
        Helpers::app()->singleton('runningInAws', fn () => static::detectAwsEnvironment());

        // bail if command should not be running
        if (! $this->shouldBeRunning($this)) {
            error(sprintf("Cannot run '%s' in current environment", $this->getName()));

            return 1;
        }

        // special handling for `yolo init` command to execute early
        if ($this instanceof InitCommand) {
            Helpers::app()->instance('environment', 'production');

            return (int) (Helpers::app()->call([$this, 'handle']) ?: 0);
        }

        if (! Manifest::exists()) {
            error("Could not find yolo.yml manifest in the current directory - run 'yolo init' to create one");

            return 1;
        }

        if (! Manifest::environmentExists($this->argument('environment'))) {
            error(sprintf("Could not find '%s' in the YOLO manifest", $this->argument('environment')));

            return 1;
        }

        Helpers::app()->instance('environment', $this->argument('environment'));

        if (static::requiresAwsProfile() && ! Helpers::keyedEnv('AWS_PROFILE')) {
            error(sprintf('You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding', strtoupper(Helpers::environment())));

            return 1;
        }

        if (! $this->ensureManifestIntegrity()) {
            return 1;
        }

        $this->registerAwsServices();

        if (! $this->ensureAccountMatchesProfile()) {
            return 1;
        }

        $exitCode = (int) (Helpers::app()->call([$this, 'handle']) ?: 0);

        foreach ($this->after as $closure) {
            $closure();
        }

        return $exitCode;
    }

    protected function ensureManifestIntegrity(): bool
    {
        return $this->ensureNameDeclared()
            && $this->ensureNoUnknownManifestKeys()
            && $this->ensureManifestKeyDeclared('region')
            && $this->ensureManifestKeyDeclared('account-id')
            && $this->ensureCacheStoreValid()
            && $this->ensureSessionDriverValid();
    }

    /**
     * The manifest is validated against a strict allow-list of keys. Any key not
     * in the schema, or a valid key in the wrong place, hard-fails so a misshapen
     * manifest can't deploy silently. Reports the fully-qualified key path and
     * links the manifest reference.
     */
    protected function ensureNoUnknownManifestKeys(): bool
    {
        $unknown = Manifest::unknownKeys();

        if ($unknown === []) {
            return true;
        }

        error(sprintf(
            "Unrecognised %s in yolo.yml: %s.\nSee the manifest reference: https://codinglabsau.github.io/yolo/reference/manifest",
            count($unknown) === 1 ? 'key' : 'keys',
            implode(', ', $unknown),
        ));

        return false;
    }

    /**
     * `cache.store` (web apps default to `redis`). `redis` provisions the shared
     * Valkey cluster; `file`/`database`/`array` opt out and are app-managed. Any
     * other store should be configured in the app's `.env`, not here.
     */
    protected function ensureCacheStoreValid(): bool
    {
        $store = Manifest::get('cache.store');

        if ($store === null) {
            return true;
        }

        $allowed = ['redis', 'file', 'database', 'array'];

        if (! in_array($store, $allowed, true)) {
            error(sprintf('yolo.yml `cache.store` must be one of: %s (redis provisions the shared Valkey; the rest are app-managed).', implode(', ', $allowed)));

            return false;
        }

        return true;
    }

    /**
     * `session.driver` (when set) must be a Laravel session driver YOLO supports.
     * `dynamodb` was removed (DynamoDB support is gone — sessions live on Valkey),
     * so it hard-fails with a pointer to redis. `redis` requires `cache.store:
     * redis` — sessions can't land on a Valkey cluster that isn't provisioned —
     * and that holds for the web-app default too, not just an explicit `redis`.
     * Hard-fail loudly rather than silently shipping a broken session backend.
     */
    protected function ensureSessionDriverValid(): bool
    {
        $driver = Manifest::get('session.driver');

        if ($driver === 'dynamodb') {
            error('yolo.yml `session.driver: dynamodb` is no longer supported — use redis. Sessions now live on the shared Valkey cluster.');

            return false;
        }

        $allowed = ['redis', 'database', 'cookie', 'file'];

        if ($driver !== null && ! in_array($driver, $allowed, true)) {
            error(sprintf('yolo.yml `session.driver` must be one of: %s.', implode(', ', $allowed)));

            return false;
        }

        // The effective driver (explicit or the web-app default) — so a web app
        // that opts the cache out (cache.store: file) without re-pinning the
        // session driver is caught, not silently shipped pointing at no cluster.
        if (Manifest::sessionDriver() === 'redis' && Manifest::cacheStore() !== 'redis') {
            error('yolo.yml `session.driver: redis` needs the Valkey cache (`cache.store: redis`, the web-app default) — don\'t opt the cache out.');

            return false;
        }

        return true;
    }

    protected function ensureNameDeclared(): bool
    {
        if (! empty(Manifest::current()['name'])) {
            return true;
        }

        error('yolo.yml must declare `name`.');

        return false;
    }

    protected function ensureManifestKeyDeclared(string $key): bool
    {
        if (Manifest::has($key)) {
            return true;
        }

        error(sprintf('yolo.yml must declare `%s`.', $key));

        return false;
    }

    protected function ensureAccountMatchesProfile(): bool
    {
        try {
            $actual = Aws::profileAccountId();
        } catch (\Throwable $e) {
            error(sprintf('Failed to verify AWS account via STS: %s', $e->getMessage()));

            return false;
        }

        if (Aws::accountId() !== $actual) {
            error(sprintf(
                'AWS account mismatch: manifest declares %s, YOLO_%s_AWS_PROFILE resolves to %s. Check .env.',
                Aws::accountId(),
                strtoupper(Helpers::environment()),
                $actual,
            ));

            return false;
        }

        return true;
    }

    protected function argument($key)
    {
        return $this->input->getArgument($key);
    }

    protected function option($key)
    {
        return $this->input->getOption($key);
    }
}
