<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Aws\Credentials\Credentials;
use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Contracts\RunsWithoutAws;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Concerns\HasAfterCallbacks;
use Codinglabs\Yolo\Contracts\ReadsEnvironment;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;
use Symfony\Component\Console\Input\InputInterface;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

abstract class Command extends SymfonyCommand
{
    use ChecksIfCommandsShouldBeRunning;
    use HasAfterCallbacks;
    use RegistersAws;

    /**
     * Enough for a spent code plus the next one, without a mistyped serial or a
     * trust-policy refusal prompting in a loop.
     */
    protected const MFA_ATTEMPTS = 3;

    public InputInterface $input;

    public OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Helpers::app()->instance('input', $this->input = $input);
        Helpers::app()->instance('output', $this->output = $output);
        Helpers::app()->singleton('runningInAws', fn (): bool => static::detectAwsEnvironment());

        // yolo ships in the image for its runtime service provider, but the CLI must
        // never run inside a deployed container holding the task role's credentials.
        if (Aws::runningInAws()) {
            error('yolo is a deploy-time CLI and cannot run inside a deployed container.');

            return 1;
        }

        if (! $this->shouldBeRunning($this)) {
            error(sprintf("Cannot run '%s' in current environment", $this->getName()));

            return 1;
        }

        // init has no manifest or environment argument yet — it prompts for and binds
        // the environment itself.
        if ($this instanceof InitCommand) {
            return (int) (Helpers::app()->call([$this, 'handle']) ?: 0);
        }

        if (($abort = $this->bootstrapEnvironment()) !== null) {
            return $abort;
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

        if (static::requiresAwsProfile() && ! $this instanceof RunsWithoutAws && ! Helpers::keyedEnv('AWS_PROFILE')) {
            error(sprintf('You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding', strtoupper((string) Helpers::environment())));

            return 1;
        }

        if (! $this->ensureManifestIntegrity()) {
            return 1;
        }

        // Resolving credentials for a RunsWithoutAws command would be circular —
        // creating them is the command's own job.
        if ($this instanceof RunsWithoutAws) {
            $exitCode = (int) (Helpers::app()->call([$this, 'handle']) ?: 0);

            foreach ($this->after as $closure) {
                $closure();
            }

            return $exitCode;
        }

        $this->registerAwsServices();

        if (! $this->ensureAccountMatchesProfile()) {
            return 1;
        }

        if (($abort = $this->mintTierCredentials()) !== null) {
            return $abort;
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
            && $this->ensureNameNotReserved()
            && $this->ensureMultitenancyKeysNested()
            && $this->ensureNoUnknownManifestKeys()
            && $this->ensureManifestKeyDeclared('region')
            && $this->ensureManifestKeyDeclared('account-id')
            && $this->ensureCacheStoreValid()
            && $this->ensureAppBucketValid()
            && $this->ensureSessionDriverValid()
            && $this->ensureServicesValid()
            && $this->ensureTasksRunnable()
            && $this->ensureWebReachable()
            && $this->ensureAutoscalingDeclared()
            && $this->ensureSchedulerHostNotScaleToZero()
            && $this->ensureQueueIsolationValid()
            && $this->ensureWildcardSubdomainsValid();
    }

    /**
     * A top-level `domain` alongside `multitenancy` is genuinely ambiguous (the
     * landlord's host, or what tenant subdomains hang off — readings that separate
     * once a tenant takes a custom domain). Runs before the unknown-key sweep so
     * these get a pointed message instead of a technically-correct useless one.
     */
    protected function ensureMultitenancyKeysNested(): bool
    {
        if (! Manifest::has('multitenancy')) {
            return true;
        }

        if (Manifest::has('domain')) {
            error(
                "yolo.yml declares both `domain` and `multitenancy` — under multi-tenancy a top-level `domain` is ambiguous (the landlord's own host, or the domain tenant subdomains hang off?).\n"
                . 'Move it to `multitenancy.landlord.domain`.'
            );

            return false;
        }

        if (Manifest::has('wildcard-subdomains')) {
            error(
                "yolo.yml declares both `wildcard-subdomains` and `multitenancy` — the flag belongs to the landlord or tenant whose domain it wildcards.\n"
                . 'Move it to `multitenancy.landlord.wildcard-subdomains`, or onto the tenant as `multitenancy.tenants.<id>.wildcard-subdomains`.'
            );

            return false;
        }

        return true;
    }

    /**
     * `wildcard-subdomains` deliberately composes with `tenants` rather than
     * excluding it: a tenant under the wildcard is already served by the app's
     * certificate and rule ({@see Manifest::servesDomain()}); a tenant on its own
     * domain still gets a zone, certificate, SNI attachment and rule.
     */
    protected function ensureWildcardSubdomainsValid(): bool
    {
        if (! Manifest::servesWildcardSubdomains()) {
            return true;
        }

        if (! Manifest::hasDomain()) {
            error('yolo.yml declares `wildcard-subdomains` but no `domain` — there is nothing to serve subdomains of. Declare `domain`, or drop the key.');

            return false;
        }

        // A www-canonical domain would put the wildcard at `*.www.{apex}` and move the
        // certificate off the apex, so the apex→www redirect would fail the TLS
        // handshake before it could 301.
        $domain = (string) Manifest::domain();

        if (str_starts_with($domain, 'www.')) {
            error(sprintf(
                'yolo.yml declares `wildcard-subdomains` with a www-canonical `domain` (%s) — the wildcard would land at *.%s and the certificate would no longer cover the apex it redirects from. Serve the app from the apex or a bare subdomain instead.',
                $domain,
                $domain,
            ));

            return false;
        }

        return true;
    }

    /**
     * With no tenants there is nothing to isolate, so the key is refused rather
     * than silently ignored.
     */
    protected function ensureQueueIsolationValid(): bool
    {
        if (! Manifest::has('multitenancy.queue-isolation')) {
            return true;
        }

        if (! Manifest::hasTenants()) {
            error('yolo.yml declares `multitenancy.queue-isolation` but no `multitenancy.tenants` — the strategy only applies to a multi-tenant app. Remove it, or declare tenants.');

            return false;
        }

        try {
            Manifest::queueIsolation();
        } catch (IntegrityCheckException $e) {
            error($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * The task security group only accepts ingress from the ALB, and with no domain
     * no listener rule ever points at the service — a Fargate task nobody can reach.
     */
    protected function ensureWebReachable(): bool
    {
        if (! Manifest::hasWeb() || ! Manifest::isHeadless()) {
            return true;
        }

        error(
            "yolo.yml declares `tasks.web` but no `domain` — a web task with no public host serves nothing (no listener rule ever routes to it).\n"
            . 'Declare `domain` (or a tenant domain), or drop `tasks.web` and run a worker app (standalone tasks.queue / tasks.scheduler).'
        );

        return false;
    }

    /**
     * A bundled queue/scheduler has no web container to ride, so a `tasks` block
     * yielding no ECS service is refused rather than silently provisioning nothing.
     * No `tasks` key at all is a build-only app and untouched.
     */
    protected function ensureTasksRunnable(): bool
    {
        if (! Manifest::has('tasks') || Manifest::serverGroups() !== []) {
            return true;
        }

        error(
            "yolo.yml declares `tasks` but nothing would run — no web task, and no standalone queue or scheduler to run instead.\n"
            . 'Declare `tasks.web`, or extract `tasks.queue` / `tasks.scheduler` into their own service (a web-less app needs at least one).'
        );

        return false;
    }

    /**
     * No implicit autoscaling default: the bare `tasks.web: true` shorthand has
     * nowhere to declare scaling behaviour, so web and queue must say so explicitly.
     * Only the scheduler (a pinned singleton) keeps the shorthand.
     */
    protected function ensureAutoscalingDeclared(): bool
    {
        $enabled = [
            ServerGroup::WEB->value => Manifest::hasWeb(),
            ServerGroup::QUEUE->value => Manifest::hasStandaloneQueue(),
        ];

        foreach ($enabled as $group => $isEnabled) {
            if ($isEnabled && ! Manifest::has("tasks.$group.autoscaling")) {
                error(sprintf(
                    'yolo.yml `tasks.%s` must declare `autoscaling` (true | false | { min, max }) — '
                    . "web and queue need an explicit scaling decision, so the bare `tasks.%s: true` shorthand isn't accepted.\n"
                    . 'See the manifest reference: https://codinglabsau.github.io/yolo/reference/manifest',
                    $group,
                    $group,
                ));

                return false;
            }
        }

        return true;
    }

    /**
     * A queue hosting the scheduler can't scale to zero — cron would stop the moment
     * it idled. The floor defaults to 1, so only an explicit `min: 0` contradicts.
     */
    protected function ensureSchedulerHostNotScaleToZero(): bool
    {
        if (Manifest::schedulerHost() !== ServerGroup::QUEUE || ! Manifest::autoscales(ServerGroup::QUEUE)) {
            return true;
        }

        if (Manifest::autoscalingMin(ServerGroup::QUEUE) === 0) {
            error(
                "yolo.yml runs the scheduler inside the standalone queue (there's no `tasks.scheduler` service), "
                . "so the queue can't scale to zero — cron would stop when it idles to 0 tasks.\n"
                . 'Set `tasks.queue.autoscaling.min` to 1 or more, or extract the scheduler into its own `tasks.scheduler` service.'
            );

            return false;
        }

        return true;
    }

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
     * `bucket: false` is refused rather than read as "no bucket" — omitting the key
     * already says that, and a silently-ignored `false` would ship an app with no
     * AWS_BUCKET. Invalid names fail here instead of as InvalidBucketName mid-apply.
     */
    protected function ensureAppBucketValid(): bool
    {
        if (! Manifest::has('bucket') || Manifest::managesAppBucket()) {
            return true;
        }

        $bucket = Manifest::get('bucket');

        if (! is_string($bucket) || ! S3::isValidBucketName($bucket)) {
            error(
                'yolo.yml `bucket` must be `true` — YOLO provisions and owns the bucket — or the name of an existing bucket to adopt '
                    . "(3-63 characters: lowercase letters, numbers, dots and hyphens, starting and ending alphanumeric).\nOmit the key entirely for no app data bucket."
            );

            return false;
        }

        return true;
    }

    /**
     * YOLO never creates a BYO bucket: its name sits outside the `yolo-*` namespace
     * the admin tier is fenced to, so CreateBucket would AccessDenied mid-apply.
     * Ownership comes from ListBuckets, not HeadBucket — a bucket owned by another
     * account answers HeadBucket with a 403 indistinguishable from "yours, but this
     * tier may not read it", and adopting it fails every runtime write.
     */
    protected function ensureAppBucketAdoptable(): bool
    {
        if (! Manifest::has('bucket') || Manifest::managesAppBucket()) {
            return true;
        }

        $bucket = Paths::s3AppBucket();

        if (S3::accountOwnsBucket($bucket)) {
            return true;
        }

        error(sprintf(
            "The app data bucket \"%s\" doesn't exist on this account%s.\n"
                . "YOLO adopts a bucket named in yolo.yml but never creates one — the name is outside the yolo-* namespace this tier may write.\n"
                . 'Either set `bucket: true` and let YOLO provision and own it as "%s", or create "%s" yourself and re-run.',
            $bucket,
            S3::bucketTaken($bucket) ? ' — the name is taken in another AWS account' : '',
            Helpers::keyedBucketName('data'),
            $bucket,
        ));

        return false;
    }

    protected function ensureSessionDriverValid(): bool
    {
        $driver = Manifest::get('session.driver');

        $allowed = ['redis', 'database', 'cookie', 'file'];

        if ($driver !== null && ! in_array($driver, $allowed, true)) {
            error(sprintf('yolo.yml `session.driver` must be one of: %s.', implode(', ', $allowed)));

            return false;
        }

        // Checks the effective driver, not just an explicit one: a web app that opts
        // the cache out without re-pinning sessions would otherwise ship pointing at
        // a Valkey cluster that isn't provisioned.
        if (Manifest::sessionDriver() === 'redis' && Manifest::cacheStore() !== 'redis') {
            error('yolo.yml `session.driver: redis` needs the Valkey cache (`cache.store: redis`, the default) — don\'t opt the cache out.');

            return false;
        }

        return true;
    }

    /**
     * Service shape lives in the environment manifest, so an app declares bare
     * names only.
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
     * An app using an env-backed service the environment doesn't declare would
     * quietly get nothing provisioned. Before the env manifest exists (greenfield,
     * first sync not yet run) there is nothing to validate against, so defer rather
     * than brick that first sync.
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

    /**
     * The harm an older CLI can do is the write, not the plan: reconciling a guarded
     * tier it doesn't fully know can walk a newer default back to its old value. So the
     * refusal keys on the plan — only a pending change in a {@see guardedScopes()} scope
     * reads the version marker, and only a provably older CLI is refused. A clean guarded
     * plan (or one pending only in an unguarded scope) proceeds without the read. The
     * deploy gate and audit run `sync --check` on the app's pinned release, which lags the
     * environment as a matter of course between releases, so they pass through to the app
     * tier's warning instead. Unordered sides (a dev pin, an unstamped or unreadable
     * marker) never refuse.
     *
     * @param  Collection<int, array{scope: string, status: mixed}>  $pending
     */
    protected function ensureCliMayApply(Collection $pending): bool
    {
        if (DeployCheck::active()) {
            return true;
        }

        $guarded = $pending->filter(fn (array $entry): bool => in_array($entry['scope'], $this->guardedScopes(), true));

        if ($guarded->isEmpty()) {
            return true;
        }

        $stamped = EnvironmentVersion::outrunBy($this->cliVersion());

        if ($stamped === null) {
            return true;
        }

        error(sprintf(
            "This yolo CLI (%s) is OLDER than the release that last synced %s (%s), and the plan holds %d pending change(s) in the %s tier — applying them from here could walk newer defaults back without flagging it.\nUpdate codinglabsau/yolo in this checkout (`composer update codinglabsau/yolo`) and re-run.",
            $this->cliVersion(),
            Helpers::environment(),
            $stamped,
            $guarded->count(),
            implode(' / ', $guarded->pluck('scope')->unique()->all()),
        ));

        return false;
    }

    /**
     * Scope labels whose pending changes an older CLI must not apply — the tiers where
     * a reconcile from a release that predates the environment can regress it. The
     * account and environment tiers declare theirs; the app tier guards nothing (an
     * app pin lagging the environment is the normal state between releases).
     *
     * @return array<int, string>
     */
    public function guardedScopes(): array
    {
        return [];
    }

    /** A seam: in a test run the real value is whatever pin the checkout is on. */
    protected function cliVersion(): string
    {
        return Helpers::version();
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
     * yolo-{env}-services is the env services cluster and app liveness derivation
     * skips it — an app actually named "services" would be invisible to the audit.
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
     * Hook for a command that must run against an environment yolo.yml no longer
     * declares (destroy:environment after destroy:app removed the block): hydrate
     * the manifest from the live account (see Manifest::hydrate) before the checks
     * read it. Returns null to proceed, or an exit code to abort.
     */
    protected function bootstrapEnvironment(): ?int
    {
        return null;
    }

    /**
     * Null runs on the developer's own profile credentials unchanged.
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
     * Cap this run to its tier by assuming the tier role and re-registering every
     * AWS client on the scoped credentials — YOLO can never exceed the tier even
     * though the developer authenticated as their broader self.
     *
     * Fail-closed: there is no "run on the full profile because the role is
     * missing" path. The only escape is --dangerously-skip-permissions
     * (bootstrap / break-glass / diagnostics).
     *
     * A nested in-process run inherits the parent's cap: the deploy → `sync --check`
     * gate runs an admin-tier SyncCommand inside a deployer-capped deploy, and
     * re-minting would climb deployer → admin — an escalation the tier model
     * forbids, and one that can't resolve an MFA device from inside a role session.
     * The deployer cap already carries the per-app observer read surface the check
     * needs.
     *
     * Returns null to proceed, or an exit code to abort the command.
     */
    protected function mintTierCredentials(): ?int
    {
        $tier = $this->awsTier();

        if (! $tier instanceof Iam) {
            return null;
        }

        if ($this->skipsPermissions()) {
            warning('--dangerously-skip-permissions: running UNCAPPED as your full AWS identity. The YOLO permission tier is bypassed.');

            return null;
        }

        // Keyed off a dedicated marker, not the minted credentials, because the
        // CI/OIDC path caps without minting any.
        if (Helpers::app()->bound('yoloTierApplied')) {
            return null;
        }

        $role = match ($tier) {
            Iam::OBSERVER_ROLE => $this->observerRole(),
            Iam::DEPLOYER_ROLE => new DeployerRole(),
            Iam::ADMIN_ROLE => new AdminRole(),
            default => null,
        };

        if (! $role instanceof Resource) {
            return null;
        }

        // CI assumes the tier role via OIDC before yolo runs; a self-assume is one
        // the role's own policy doesn't (and shouldn't) grant. Gated to CI so a
        // local run skips the extra GetCallerIdentity call.
        if (static::detectCiEnvironment() && $this->callerIsTierRole($role)) {
            Helpers::app()->instance('yoloTierApplied', true);

            return null;
        }

        try {
            // Never resolve the ARN live: a tier member's base identity holds nothing
            // but the group grants, so a GetRole/ListRoles here is an AccessDenied
            // that blocks the assume it was trying to help.
            $roleArn = sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), $role->name());
            $sessionName = sprintf('yolo-%s', $tier->value);

            if ($tier === Iam::ADMIN_ROLE) {
                if (($mfaSerial = $this->resolveMfaSerial()) === null) {
                    error(sprintf(
                        "Refusing to mint the admin tier: no MFA device found for your identity.\n"
                        . 'Attach an MFA device, or set YOLO_%s_MFA_SERIAL to its ARN. Admin requires MFA so escalating to it is always an explicit human act.',
                        strtoupper((string) Helpers::environment()),
                    ));

                    return self::FAILURE;
                }

                $credentials = $this->assumeAdminRole($roleArn, $sessionName, $mfaSerial);
            } else {
                $credentials = Aws::assumeRole($roleArn, $sessionName);
            }

            Helpers::app()->instance('yoloAssumedCredentials', new Credentials(
                $credentials['AccessKeyId'],
                $credentials['SecretAccessKey'],
                $credentials['SessionToken'] ?? null,
            ));
            Helpers::app()->instance('yoloTierApplied', true);

            static::forgetAwsClients();
            $this->registerAwsServices();

            return null;
        } catch (\Throwable $e) {
            // A rejected TOTP is an operator slip, not a broken tier — don't send
            // them off to check role existence or MFA enrolment.
            error(static::isRejectedMfaCode($e)
                ? sprintf(
                    "Refusing to run '%s': AWS rejected the MFA code.\n"
                    . 'Each code mints one session only — a code already spent (on an earlier run, or entered twice) is denied for the rest of its window, even while your authenticator still shows it. Wait for the next code and run the command again.',
                    $this->getName(),
                )
                : sprintf(
                    "Refusing to run '%s' on your full AWS identity: could not assume %s (%s).\n"
                    . 'Every YOLO tier requires MFA — sessions minted without it are denied, so if this is an AccessDenied check your credentials carry MFA (`yolo configure %s` sets that up and verifies it). '
                    . 'Bootstrap a fresh environment once with --dangerously-skip-permissions; otherwise check the role exists and that your identity may assume it.',
                    $this->getName(),
                    $role->name(),
                    $e->getMessage(),
                    Helpers::environment(),
                ));

            return self::FAILURE;
        }
    }

    /**
     * A TOTP mints exactly one session, so re-entering the code still on screen is
     * by far the likeliest failure; re-prompting costs seconds, aborting costs the
     * whole run. Only a rejected code retries — nothing else is fixed by another one.
     *
     * @return array<string, mixed>
     */
    protected function assumeAdminRole(string $roleArn, string $sessionName, string $mfaSerial): array
    {
        $rejected = [];

        while (true) {
            $code = $this->promptMfaCode($rejected);

            try {
                return Aws::assumeRole($roleArn, $sessionName, $mfaSerial, $code);
            } catch (\Throwable $e) {
                if (count($rejected) + 1 >= self::MFA_ATTEMPTS || ! static::isRejectedMfaCode($e)) {
                    throw $e;
                }

                $rejected[] = $code;

                warning('AWS rejected that code. Each code mints a single session — wait for your authenticator to roll to the next one.');
            }
        }
    }

    /**
     * Matched on the message because STS reports a rejected TOTP as a plain
     * AccessDenied, indistinguishable by code from a trust-policy refusal. The
     * "one time pass code" phrasing keeps the wrong-serial failure out — another
     * code never fixes that.
     */
    protected static function isRejectedMfaCode(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'MultiFactorAuthentication failed')
            && str_contains($e->getMessage(), 'one time pass code');
    }

    /**
     * Mirror of {@see mintTierCredentials()} for {@see RunsOnBaseCredentials}: a
     * command can't delete the role + policy it's authenticated under, so the IAM
     * teardown slice runs on the base identity. No-op when nothing was minted (the
     * CI/OIDC path IS the tier role and has nothing broader to fall back to).
     */
    public function ensureBaseCredentials(): void
    {
        if (! Helpers::app()->bound('yoloAssumedCredentials')) {
            return;
        }

        Helpers::app()->forgetInstance('yoloAssumedCredentials');
        static::forgetAwsClients();
        $this->registerAwsServices();
    }

    /**
     * A subprocess (aws CLI, session-manager-plugin) must run on the tier, not
     * --profile: that resolves the operator's FULL identity, escaping the cap and
     * failing for least-privileged members whose base identity holds only the group
     * grants. Env credentials outrank every other source in the CLI's chain.
     *
     * @return array<string, string>|null
     */
    protected function subprocessEnv(): ?array
    {
        if (! Helpers::app()->bound('yoloAssumedCredentials')) {
            return null;
        }

        $minted = Helpers::app('yoloAssumedCredentials');

        return [
            'AWS_ACCESS_KEY_ID' => $minted->getAccessKeyId(),
            'AWS_SECRET_ACCESS_KEY' => $minted->getSecretKey(),
            'AWS_SESSION_TOKEN' => $minted->getSecurityToken(),
        ];
    }

    /**
     * Whenever tier credentials were minted, {@see subprocessEnv()} exports them and
     * the profile must stay out of the invocation entirely.
     */
    protected function subprocessProfile(): ?string
    {
        return Helpers::app()->bound('yoloAssumedCredentials')
            ? null
            : Helpers::keyedEnv('AWS_PROFILE');
    }

    /**
     * An STS assumed-role ARN is `arn:aws:sts::<account>:assumed-role/<role-name>/<session>`;
     * YOLO roles carry no IAM path, so the role name alone identifies the role.
     */
    protected function callerIsTierRole(Resource $role): bool
    {
        if (preg_match('#:assumed-role/([^/]+)/#', Aws::callerArn(), $matches) !== 1) {
            return false;
        }

        return $matches[1] === $role->name();
    }

    /**
     * The `YOLO_{ENV}_MFA_SERIAL` override needs no IAM permission; auto-discovery
     * needs `iam:ListMFADevices`.
     */
    protected function resolveMfaSerial(): ?string
    {
        if ($serial = Helpers::keyedEnv('MFA_SERIAL')) {
            return $serial;
        }

        return Aws::callerMfaSerial();
    }

    /**
     * Already-rejected codes are refused here rather than sent back to STS: the code
     * is still on the authenticator for the rest of its window, so re-entering it is
     * natural and guarantees a second rejection.
     *
     * @param  array<int, string>  $rejected  codes AWS has refused this run
     */
    protected function promptMfaCode(array $rejected = []): string
    {
        return text(
            label: 'MFA code (admin tier)',
            placeholder: '123456',
            required: true,
            validate: function (string $value) use ($rejected): ?string {
                if (preg_match('/^\d{6}$/', $value) !== 1) {
                    return 'Enter the 6-digit code from your MFA device.';
                }

                return in_array($value, $rejected, true)
                    ? 'AWS already rejected that code — a code mints one session only. Wait for the next one.'
                    : null;
            },
            hint: $rejected === []
                ? 'Admin requires MFA — it proves a human, not an agent, is escalating.'
                : 'Each code mints one session only — wait for your authenticator to roll over.',
        );
    }

    /**
     * Per-app observer by default so a read grant can name one app; env-wide reads
     * (status:environment, every audit verb) declare {@see ReadsEnvironment}.
     */
    protected function observerRole(): Resource
    {
        return $this instanceof ReadsEnvironment
            ? new ObserverRole()
            : new AppObserverRole();
    }

    /**
     * Registered on the application (see Yolo); input may not be bound yet under
     * direct unit invocation.
     */
    protected function skipsPermissions(): bool
    {
        return isset($this->input)
            && $this->input->hasOption('dangerously-skip-permissions')
            && (bool) $this->input->getOption('dangerously-skip-permissions');
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
