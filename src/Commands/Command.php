<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Aws\Credentials\Credentials;
use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Concerns\HasAfterCallbacks;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

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
        Helpers::app()->singleton('runningInAws', fn (): bool => static::detectAwsEnvironment());

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
            error(sprintf('You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding', strtoupper((string) Helpers::environment())));

            return 1;
        }

        if (! $this->ensureManifestIntegrity()) {
            return 1;
        }

        $this->registerAwsServices();

        if (! $this->ensureAccountMatchesProfile()) {
            return 1;
        }

        // Cap this run to its YOLO tier: mint an assumed-role token scoped to the
        // tier's policy (the developer authenticates as themselves; YOLO can never
        // exceed the tier). Self-activating + fail-open — a no-op until the tier
        // role is provisioned.
        $this->mintTierCredentials();

        $exitCode = (int) (Helpers::app()->call([$this, 'handle']) ?: 0);

        foreach ($this->after as $closure) {
            $closure();
        }

        return $exitCode;
    }

    protected function ensureManifestIntegrity(): bool
    {
        return $this->ensureNameDeclared()
            && $this->ensureNameNotReserved()
            && $this->ensureNoUnknownManifestKeys()
            && $this->ensureManifestKeyDeclared('region')
            && $this->ensureManifestKeyDeclared('account-id')
            && $this->ensureCacheStoreValid()
            && $this->ensureSessionDriverValid()
            && $this->ensureServicesValid()
            && $this->ensureSchedulerHostNotScaleToZero();
    }

    /**
     * When the scheduler rides the standalone queue (a `tasks.queue` block but no
     * dedicated `tasks.scheduler` service), the queue can't scale to zero — cron
     * would stop the moment it idled to no tasks. The floor defaults to 1 in that
     * topology, but an explicit `tasks.queue.min: 0` is a contradiction, so
     * hard-fail and point at the two ways out.
     */
    protected function ensureSchedulerHostNotScaleToZero(): bool
    {
        if (Manifest::schedulerHost() !== ServerGroup::QUEUE) {
            return true;
        }

        $min = Manifest::get('tasks.queue.min');

        if ($min !== null && is_numeric($min) && (int) $min === 0) {
            error(
                "yolo.yml runs the scheduler inside the standalone queue (there's no `tasks.scheduler` service), "
                . "so the queue can't scale to zero — cron would stop when it idles to 0 tasks.\n"
                . 'Set `tasks.queue.min` to 1 or more, or extract the scheduler into its own `tasks.scheduler` service.'
            );

            return false;
        }

        return true;
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
     * `redis` requires `cache.store: redis` — sessions can't land on a Valkey
     * cluster that isn't provisioned — and that holds for the web-app default too,
     * not just an explicit `redis`. Hard-fail loudly rather than silently shipping
     * a broken session backend.
     */
    protected function ensureSessionDriverValid(): bool
    {
        $driver = Manifest::get('session.driver');

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

    /**
     * `services` is the app's opt-in list of YOLO-provisioned services — bare
     * capability names only (the Service enum). All service shape lives in the
     * environment manifest, so there's nothing else an app may declare here:
     * not an object, not duplicates, not an unknown name.
     */
    protected function ensureServicesValid(): bool
    {
        $services = Manifest::get('services');

        if ($services === null) {
            return true;
        }

        if (! is_array($services) || ! array_is_list($services)) {
            error(sprintf('yolo.yml `services` must be a list of service names (%s).', implode(', ', Service::values())));

            return false;
        }

        $unknown = array_diff($services, Service::values());

        if ($unknown !== []) {
            error(sprintf(
                'Unknown %s in yolo.yml `services`: %s. Available: %s.',
                count($unknown) === 1 ? 'service' : 'services',
                implode(', ', $unknown),
                implode(', ', Service::values()),
            ));

            return false;
        }

        if (count($services) !== count(array_unique($services))) {
            error('yolo.yml `services` contains duplicate entries.');

            return false;
        }

        return true;
    }

    /**
     * An app may only use an env-backed service the environment manifest
     * declares — otherwise the app would publish what it uses, and the
     * environment would quietly provision nothing. Build, deploy and sync:app
     * hard-fail it with the fix spelled out. Before the env manifest exists
     * (a greenfield environment the first sync hasn't seeded yet) there is
     * nothing to validate against, so the check defers to that first sync
     * rather than bricking it.
     */
    protected function ensureClaimedServicesOffered(): bool
    {
        $envBacked = array_filter(
            Manifest::services(),
            fn (string $service): bool => Service::from($service)->definition()->envBacked(),
        );

        if ($envBacked === []) {
            return true;
        }

        if (! EnvManifest::remoteExists()) {
            return true;
        }

        $missing = array_values(array_filter(
            $envBacked,
            fn (string $service): bool => ! EnvManifest::has(Service::from($service)->envManifestKey()),
        ));

        if ($missing === []) {
            return true;
        }

        error(sprintf(
            "This app uses the %s service%s, but %s doesn't declare %s yet.\nDeclare services.%s with `yolo environment:manifest:pull %s` / `yolo environment:manifest:push %s`, or remove %s from yolo.yml's services list.",
            implode(', ', $missing),
            count($missing) === 1 ? '' : 's',
            EnvManifest::filename(),
            count($missing) === 1 ? 'it' : 'them',
            implode(', services.', $missing),
            Helpers::environment(),
            Helpers::environment(),
            count($missing) === 1 ? 'it' : 'them',
        ));

        return false;
    }

    protected function ensureNameDeclared(): bool
    {
        if (! empty(Manifest::current()['name'])) {
            return true;
        }

        error('yolo.yml must declare `name`.');

        return false;
    }

    /**
     * `services` is reserved: yolo-{env}-services is the env services cluster
     * (shared service tasks, not an app), and app liveness derivation skips
     * it — an app actually named "services" would be invisible to the claims
     * registry and the audit.
     */
    protected function ensureNameNotReserved(): bool
    {
        if (Manifest::name() !== Audit::RESERVED_APP_NAME) {
            return true;
        }

        error(sprintf('yolo.yml `name` cannot be "%s" — it is reserved for the env services cluster (yolo-{env}-%s).', Audit::RESERVED_APP_NAME, Audit::RESERVED_APP_NAME));

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
                strtoupper((string) Helpers::environment()),
                $actual,
            ));

            return false;
        }

        return true;
    }

    /**
     * The YOLO permission tier this command runs under, or null to run on the
     * developer's own profile credentials unchanged. Read commands run under the
     * read-only Observer tier; the deploy lifecycle runs under the Deployer tier.
     * The Admin tier (sync/scale) is a follow-up. The base default is null, so an
     * un-tiered command is untouched.
     */
    protected function awsTier(): ?Iam
    {
        return match (true) {
            $this instanceof ReadOnlyCommand => Iam::OBSERVER_ROLE,
            $this instanceof DeployerCommand => Iam::DEPLOYER_ROLE,
            $this instanceof AdminCommand => Iam::ADMIN_ROLE,
            default => null,
        };
    }

    /**
     * Cap this run to its tier: assume the tier's role (whose policy is the tier)
     * and re-register every AWS client against the resulting scoped credentials,
     * so YOLO can never exceed the tier even though the developer authenticated as
     * their (broader) self — privilege escalation impossible by construction.
     *
     * Self-activating: a no-op until the tier role is provisioned (the developer
     * keeps running on their profile until they opt in by syncing the role).
     * Fail-open: any problem minting leaves YOLO on the profile credentials it
     * already validated, so a misconfigured role/trust never bricks a command.
     */
    protected function mintTierCredentials(): void
    {
        $tier = $this->awsTier();

        if (! $tier instanceof Iam) {
            return;
        }

        try {
            $role = match ($tier) {
                Iam::OBSERVER_ROLE => new ObserverRole(),
                Iam::DEPLOYER_ROLE => new DeployerRole(),
                Iam::ADMIN_ROLE => new AdminRole(),
                default => null,
            };

            if (! $role instanceof Resource || ! $role->exists()) {
                return;
            }

            $credentials = Aws::assumeRole($role->arn(), sprintf('yolo-%s', $tier->value));

            Helpers::app()->instance('yoloAssumedCredentials', new Credentials(
                $credentials['AccessKeyId'],
                $credentials['SecretAccessKey'],
                $credentials['SessionToken'] ?? null,
            ));

            static::forgetAwsClients();
            $this->registerAwsServices();
        } catch (\Throwable $e) {
            warning(sprintf(
                'Could not assume the %s role (%s); continuing on the profile credentials.',
                $tier->value,
                $e->getMessage(),
            ));
        }
    }

    protected function argument(string $key)
    {
        return $this->input->getArgument($key);
    }

    protected function option(string $key)
    {
        return $this->input->getOption($key);
    }
}
