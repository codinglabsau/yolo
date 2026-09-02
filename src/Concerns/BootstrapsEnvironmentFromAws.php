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
 * Lets `destroy:environment` run against an environment yolo.yml no longer
 * declares (`destroy:app` removes the block as its final act): account-id from STS,
 * region from the AWS profile, domain + services from the published env manifest.
 * Gated on the operator typing the resolved account-id back, since there's no local
 * block to match the profile against.
 */
trait BootstrapsEnvironmentFromAws
{
    /**
     * Returns null to proceed, or an exit code to abort.
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

        // A region-only hydrate is enough to register clients (they need region +
        // credentials, not the account-id). The base flow's own registration runs
        // only after this hook; a test that has pre-bound mock clients keeps them.
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

        // The env config bucket name needs the account-id.
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

        // The env manifest stores services as a map; the app manifest's usesService()
        // reads the bare-name list form.
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
     * Bypassed by --force / non-interactive, exactly as the typed environment-name confirm is.
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
     * Env-scope teardown never uses the app name, but the base command requires a
     * declared, non-reserved one.
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
