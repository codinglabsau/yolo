<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Env;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Aws\Credentials\CredentialProvider;

use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

/**
 * Reconstructs an environment's manifest config from the live account so a command
 * can run against an environment yolo.yml no longer declares — `destroy:environment`,
 * after `destroy:app` removed the block (its expected final act). The environment is
 * intentionally decoupled from the app manifest: its account-id comes from the
 * credential (STS), its region from the AWS profile, and its domain + services from
 * the published env manifest in S3.
 *
 * Resolution prefers what it can determine and prompts only for what it can't — a
 * CLI safety net rather than a hard failure. The whole operation is then gated on
 * the operator typing the resolved account-id back, on top of the admin-tier MFA
 * (minted later) and the typed environment-name confirm at the plan gate.
 */
trait BootstrapsEnvironmentFromAws
{
    /**
     * Resolve the environment from the live account and hydrate the manifest with it,
     * so the standard command flow runs unchanged. Returns null to proceed, or an
     * exit code to abort.
     */
    protected function bootstrapEnvironmentFromAws(string $environment): ?int
    {
        Helpers::app()->instance('environment', $environment);

        if (($profile = $this->resolveProfile()) === null) {
            return self::FAILURE;
        }

        if (($region = $this->resolveRegion($profile)) === null) {
            return self::FAILURE;
        }

        // Register AWS against the resolved profile + region — a region-only hydrate
        // is enough, since clients need region + credentials, not the account-id — so
        // the account-id (STS) and the env manifest (S3) read through the standard
        // clients. The base flow's own registration runs only after this hook, so the
        // bootstrap registers here; a test that has pre-bound mock clients keeps them.
        $name = $this->localManifestName($environment);
        Manifest::hydrate($this->synthesiseManifest($name, $environment, null, $region));

        if (! Helpers::app()->bound('sts')) {
            $this->registerAwsServices();
        }

        try {
            $accountId = Aws::profileAccountId();
        } catch (\Throwable $exception) {
            error(sprintf(
                "Couldn't resolve the AWS account from profile '%s' (%s).\nCheck the profile's credentials, or set %s in your .env.",
                $profile,
                $exception->getMessage(),
                Helpers::keyedEnvName('AWS_PROFILE'),
            ));

            return self::FAILURE;
        }

        if (! $this->confirmAccountId($environment, $accountId, $region)) {
            warning('Account ID did not match — nothing was destroyed.');

            return self::FAILURE;
        }

        // Now the account-id is known, hydrate it so the env config bucket
        // (yolo-{account-id}-{env}-config) resolves for the env manifest read.
        Manifest::hydrate($this->synthesiseManifest($name, $environment, $accountId, $region));

        if (! EnvManifest::remoteExists()) {
            error(sprintf(
                "Couldn't find the published environment manifest at s3://%s/%s.\n"
                . "The '%s' environment may already be gone, or was never synced. Restore its block in yolo.yml to tear it down from the local manifest instead.",
                Paths::s3EnvConfigBucket(),
                EnvManifest::filename(),
                $environment,
            ));

            return self::FAILURE;
        }

        // Re-hydrate complete: domain + the services list (the env manifest stores
        // services as a map of service => config; the app manifest's usesService()
        // reads the bare-name list form, so flatten to its keys).
        $services = EnvManifest::get('services', []);
        Manifest::hydrate($this->synthesiseManifest(
            $name,
            $environment,
            $accountId,
            $region,
            EnvManifest::get('domain'),
            array_keys(is_array($services) ? $services : []),
        ));

        return null;
    }

    /**
     * The AWS profile to run under: the .env value if set, else prompt (a genuinely
     * non-interactive run with no profile falls through to the base command's
     * existing "specify YOLO_<ENV>_AWS_PROFILE" error / the SDK default chain in CI).
     * A prompted value is written back to the env repository so the existing
     * credential resolution picks it up unchanged.
     */
    protected function resolveProfile(): ?string
    {
        if ($profile = Helpers::keyedEnv('AWS_PROFILE')) {
            return $profile;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $profile = trim(text(
            label: 'AWS profile to tear this environment down with',
            placeholder: 'my-app-production',
            required: true,
            hint: sprintf('Not found as %s in .env — name the profile to use.', Helpers::keyedEnvName('AWS_PROFILE')),
        ));

        Env::getRepository()->set(Helpers::keyedEnvName('AWS_PROFILE'), $profile);

        return $profile;
    }

    /**
     * The region the environment lives in: an explicit YOLO_<ENV>_AWS_REGION wins,
     * else the profile's configured region from ~/.aws/config, else prompt.
     */
    protected function resolveRegion(string $profile): ?string
    {
        if ($region = Helpers::keyedEnv('AWS_REGION')) {
            return $region;
        }

        if ($region = $this->profileConfiguredRegion($profile)) {
            return $region;
        }

        if (! $this->input->isInteractive()) {
            error(sprintf(
                "Couldn't determine the region for profile '%s'.\nSet a region on the profile in ~/.aws/config, or %s in your .env.",
                $profile,
                Helpers::keyedEnvName('AWS_REGION'),
            ));

            return null;
        }

        return trim(text(
            label: 'AWS region the environment lives in',
            placeholder: 'ap-southeast-2',
            required: true,
            hint: 'The profile declares no region and YOLO_<ENV>_AWS_REGION is unset.',
        ));
    }

    /** The `region` configured for the named profile in ~/.aws/config, or null. */
    protected function profileConfiguredRegion(string $profile): ?string
    {
        $configFile = CredentialProvider::getConfigFileName();

        if (! is_file($configFile)) {
            return null;
        }

        $config = @parse_ini_file($configFile, true) ?: [];
        $section = $profile === 'default' ? 'default' : "profile $profile";

        return $config[$section]['region'] ?? null;
    }

    /**
     * Gate the teardown on the operator typing the resolved account-id back — the
     * which-account safety net that replaces the manifest-account-id↔profile match
     * when there's no local block to match against. Bypassed by --force /
     * non-interactive (CI), exactly as the typed environment-name confirm is.
     */
    protected function confirmAccountId(string $environment, string $accountId, string $region): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '  Tearing down <options=bold>%s</> in account <options=bold>%s</> (%s) — reconstructed from the live account, not yolo.yml.',
            $environment,
            $accountId,
            $region,
        ));
        $this->output->writeln('');

        return text(
            label: 'Type the account ID to confirm this is the right account',
            placeholder: $accountId,
            hint: 'Anything that is not an exact match cancels — nothing is deleted.',
        ) === $accountId;
    }

    /**
     * The app name for the hydrated manifest: the on-disk yolo.yml name when present
     * (a teardown run from the app repo), else the environment name. Env-scope
     * teardown never uses the app name, but the base command requires a declared,
     * non-reserved name.
     */
    protected function localManifestName(string $fallback): string
    {
        if (file_exists(Paths::manifest())) {
            $name = Yaml::parse((string) file_get_contents(Paths::manifest()))['name'] ?? null;

            if (! empty($name)) {
                return $name;
            }
        }

        return $fallback;
    }

    /**
     * Build the synthetic single-environment manifest hydrated in place of yolo.yml.
     *
     * @param  array<int, string>  $services
     * @return array<string, mixed>
     */
    protected function synthesiseManifest(string $name, string $environment, ?string $accountId, string $region, ?string $domain = null, array $services = []): array
    {
        return [
            'name' => $name,
            'environments' => [
                $environment => array_filter([
                    'account-id' => $accountId,
                    'region' => $region,
                    'domain' => $domain,
                    'services' => $services,
                ], fn (mixed $value): bool => $value !== null && $value !== []),
            ],
        ];
    }
}
