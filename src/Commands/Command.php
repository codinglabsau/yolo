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
     * The manifest is validated against a strict allow-list of keys (no AWS
     * namespace — every key sits at the top of the environment block). Any key
     * not in the schema, or a valid key in the wrong place, hard-fails so a
     * misshapen manifest can't deploy silently. There is no back-compat for the
     * old `aws.*` keys — the error points straight at what moved.
     */
    protected function ensureNoUnknownManifestKeys(): bool
    {
        $unknown = Manifest::unknownKeys();

        if ($unknown === []) {
            return true;
        }

        error(sprintf('yolo.yml has unknown or misplaced keys: %s.', implode(', ', $unknown)));

        return false;
    }

    /**
     * `cache.store`, when set, must be `redis` — the only cache store YOLO
     * provisions (the shared Valkey cluster). Other stores are the app's own
     * `.env` concern.
     */
    protected function ensureCacheStoreValid(): bool
    {
        $store = Manifest::get('cache.store');

        if ($store === null || $store === 'redis') {
            return true;
        }

        error('yolo.yml `cache.store` must be `redis` (the Valkey cache) — set any other cache store in your .env.');

        return false;
    }

    /**
     * `session.driver` (when set) must be a Laravel session driver YOLO supports,
     * and `redis` requires `cache.store: redis` — there's no redis store without
     * the Valkey cache. Hard-fail loudly rather than silently shipping a broken
     * session backend.
     */
    protected function ensureSessionDriverValid(): bool
    {
        $driver = Manifest::get('session.driver');

        if ($driver === null) {
            return true;
        }

        $allowed = ['redis', 'dynamodb', 'database', 'cookie', 'file'];

        if (! in_array($driver, $allowed, true)) {
            error(sprintf('yolo.yml `session.driver` must be one of: %s.', implode(', ', $allowed)));

            return false;
        }

        if ($driver === 'redis' && Manifest::get('cache.store') !== 'redis') {
            error('yolo.yml `session.driver: redis` needs `cache.store: redis` — the Valkey cache is the redis store.');

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
