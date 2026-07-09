<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Enums\CredentialsDriver;
use Codinglabs\Yolo\Contracts\RunsWithoutAws;
use Codinglabs\Yolo\Credentials\SharedIniFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Set this machine up to authenticate an environment — the developer-laptop
 * half of onboarding (the account half is an IAM user + `yolo permissions`).
 * Installs the yolo-credentials helper, writes the AWS profile with a
 * credential_process line, wires YOLO_{ENV}_AWS_PROFILE into the app's .env,
 * and proves the whole chain with a live STS call. Every known way this setup
 * silently breaks — SSO remnants in the profile, a static-key section
 * shadowing credential_process — is detected and offered a fix, not left to
 * surface as a cryptic runtime error.
 */
class ConfigureCommand extends Command implements RunsWithoutAws
{
    /**
     * Whether the verified 1Password item carries a TOTP field — null until
     * (unless) the 1Password driver verified an item. Feeds the MFA posture
     * report; the custom-process driver leaves it unknown.
     */
    protected ?bool $itemHasTotp = null;

    protected function configure(): void
    {
        $this
            ->setName('configure')
            ->setDescription('Set up this machine\'s AWS profile and credentials for an environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Credential source: 1password | process');
    }

    public function handle(): int
    {
        intro(sprintf('Configure AWS credentials — %s', Helpers::environment()));

        $driver = $this->resolveDriver();

        if (! $driver instanceof CredentialsDriver || ! $this->ensureBinaries($driver)) {
            return self::FAILURE;
        }

        $this->ensureSessionManagerPlugin();

        $credentialProcess = $driver === CredentialsDriver::OnePassword
            ? $this->onePasswordCredentialProcess()
            : $this->customCredentialProcess();

        if ($credentialProcess === null) {
            return self::FAILURE;
        }

        $profile = text(
            label: 'AWS profile name',
            placeholder: 'eg. myapp-production',
            default: sprintf('%s-%s', Manifest::name(), Helpers::environment()),
            required: true,
            hint: 'Profiles map to AWS accounts — reuse one profile for every app in the same account.',
        );

        if (! $this->writeProfile($profile, $credentialProcess)) {
            return self::FAILURE;
        }

        $this->ensureNoShadowingStaticKeys($profile);
        $this->writeEnvProfile($profile);

        return $this->verify($profile) ? self::SUCCESS : self::FAILURE;
    }

    protected function resolveDriver(): ?CredentialsDriver
    {
        if (($option = $this->option('driver')) !== null) {
            $driver = CredentialsDriver::tryFrom((string) $option);

            if ($driver === null) {
                error(sprintf(
                    'Unknown --driver "%s". Available: %s.',
                    $option,
                    implode(', ', array_column(CredentialsDriver::cases(), 'value')),
                ));
            }

            return $driver;
        }

        return CredentialsDriver::from((string) select(
            label: 'Where will this machine keep its long-lived AWS key?',
            options: collect(CredentialsDriver::cases())
                ->mapWithKeys(fn (CredentialsDriver $driver): array => [$driver->value => $driver->label()])
                ->all(),
            default: CredentialsDriver::OnePassword->value,
        ));
    }

    /**
     * The AWS CLI runs the verification (and is what the developer uses
     * day-to-day); the 1Password driver's helper additionally shells out to
     * `op` and `jq`. Fail with the install one-liner rather than letting the
     * first mint die mid-script.
     */
    protected function ensureBinaries(CredentialsDriver $driver): bool
    {
        $finder = new ExecutableFinder();

        $required = $driver === CredentialsDriver::OnePassword
            ? ['aws', 'jq', 'op']
            : ['aws'];

        $missing = array_values(array_filter($required, fn (string $binary): bool => $finder->find($binary) === null));

        if ($missing === []) {
            return true;
        }

        error(sprintf('Missing required %s: %s.', count($missing) === 1 ? 'binary' : 'binaries', implode(', ', $missing)));
        note("Install with Homebrew:\n  brew install awscli jq\n  brew install --cask 1password-cli\nThen enable 1Password → Settings → Developer → Integrate with 1Password CLI.");

        return false;
    }

    /**
     * `yolo run` / `yolo db:tunnel` open a shell (or forward a port) into a
     * running container via ECS Exec, which needs AWS's Session Manager plugin
     * on this machine — a per-machine tool, same cadence as the binaries above,
     * so it's set up here rather than at app scaffolding. Non-fatal: it's not
     * needed for configure itself, only for those later commands, so a missing
     * plugin offers an install and warns rather than aborting.
     */
    protected function ensureSessionManagerPlugin(): void
    {
        if ((new ExecutableFinder())->find('session-manager-plugin')) {
            info('AWS Session Manager plugin found.');

            return;
        }

        note("The AWS Session Manager plugin isn't installed — `yolo run` and `yolo db:tunnel` need it to reach a running container.");

        if (PHP_OS_FAMILY === 'Darwin' && $this->input->isInteractive() && (new ExecutableFinder())->find('brew') && confirm('Install it now with Homebrew? (you may be prompted for your password)', default: true)) {
            (new Process(['brew', 'install', '--cask', 'session-manager-plugin']))
                ->setTty(Process::isTtySupported())
                ->setTimeout(null)
                ->run();

            return;
        }

        warning('Install it before using `yolo run` / `yolo db:tunnel`: https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html');
    }

    /**
     * The batteries-included driver: install the bundled helper, collect the
     * 1Password item, verify it up front, and build the credential_process
     * line (the vault rides along only when it isn't the Employee default).
     */
    protected function onePasswordCredentialProcess(): ?string
    {
        $helper = $this->installHelper();

        $item = text(
            label: '1Password item holding the AWS access key',
            placeholder: 'eg. AWS Acme Production',
            required: true,
            hint: 'Fields: aws_access_key_id, aws_secret_access_key — plus a one-time-password field when the IAM user has an MFA device.',
        );

        $vault = text(label: '1Password vault', default: 'Employee');

        if (! $this->verifyOnePasswordItem($item, $vault) && ! confirm('Continue anyway and fix the item later?', default: false)) {
            return null;
        }

        return $vault === 'Employee'
            ? sprintf('%s "%s"', $helper, $item)
            : sprintf('%s "%s" "%s"', $helper, $item, $vault);
    }

    /**
     * Install the bundled helper from the composer package to a stable path —
     * ~/.aws/config outlives any one checkout, so the profile never points into
     * vendor/. Always writes, so a `composer update` refresh reaches the
     * installed copy on the next configure run.
     */
    protected function installHelper(): string
    {
        $directory = $this->localBinDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $helper = $directory . '/yolo-credentials';

        copy(Paths::bin('yolo-credentials'), $helper);
        chmod($helper, 0755);

        info(sprintf('Installed the yolo-credentials helper to %s.', $helper));

        return $helper;
    }

    /**
     * The driver seam: any command that emits AWS credential JSON on stdout —
     * another password manager's CLI wrapped in a script, a corporate vault,
     * an adapted copy of yolo-credentials.
     */
    protected function customCredentialProcess(): ?string
    {
        return text(
            label: 'credential_process command',
            placeholder: 'eg. /Users/you/.local/bin/my-credentials-helper',
            required: true,
            hint: 'Absolute path — the AWS SDK does not expand ~. Must print {"Version":1,"AccessKeyId":...} JSON on stdout.',
        );
    }

    /**
     * Check the item exists and carries the two key fields before anything is
     * written, so a typo'd item name fails here with a named cause instead of
     * as jq noise on the first mint.
     */
    protected function verifyOnePasswordItem(string $item, string $vault): bool
    {
        $process = new Process(['op', 'item', 'get', $item, '--vault', $vault, '--format', 'json']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            warning(sprintf("Couldn't read '%s' from the %s vault: %s", $item, $vault, trim($process->getErrorOutput())));

            return false;
        }

        $fields = collect(json_decode($process->getOutput(), true)['fields'] ?? []);

        $missing = array_values(array_diff(
            ['aws_access_key_id', 'aws_secret_access_key'],
            $fields->pluck('label')->all(),
        ));

        if ($missing !== []) {
            warning(sprintf("'%s' is missing the %s field%s.", $item, implode(' and ', $missing), count($missing) === 1 ? '' : 's'));

            return false;
        }

        // Remembered for the MFA posture report after verification — the helper
        // can only forward MFA when the item carries a TOTP to forward.
        $this->itemHasTotp = $fields->contains(fn (array $field): bool => ($field['type'] ?? null) === 'OTP');

        info(sprintf("1Password item '%s' verified.", $item));

        return true;
    }

    /**
     * Write the profile block. An existing profile is replaced, not layered
     * onto — SSO remnants (`sso_*` keys, an `sso_session` reference) steer
     * credential resolution away from credential_process entirely, so they're
     * surfaced by name before the block is overwritten.
     */
    protected function writeProfile(string $profile, string $credentialProcess): bool
    {
        $config = $this->awsConfigFile();

        if ($config->hasSection($profile)) {
            $ssoKeys = $config->sectionKeysMatching($profile, 'sso');

            if ($ssoKeys !== []) {
                warning(sprintf(
                    'The existing [%s] profile carries SSO configuration (%s) — the CLI would try SSO and ignore credential_process.',
                    $profile,
                    implode(', ', $ssoKeys),
                ));
            }

            if (! confirm(sprintf('Replace the existing [%s] profile block?', $profile), default: true)) {
                error('Aborted — the existing profile block was left untouched.');

                return false;
            }
        }

        $config->putSection($profile, [
            'credential_process = ' . $credentialProcess,
            'region = ' . Manifest::get('region'),
        ]);

        info(sprintf('Wrote profile [%s] to %s.', $profile, $this->awsDirectory() . '/config'));

        return true;
    }

    /**
     * A same-named section in ~/.aws/credentials wins over credential_process
     * — the classic silent failure where static keys keep working and the
     * short-lived-session setup does nothing. Offer to remove it.
     */
    protected function ensureNoShadowingStaticKeys(string $profile): void
    {
        $credentials = new SharedIniFile($this->awsDirectory() . '/credentials', prefixedSections: false);

        if (! $credentials->hasSection($profile)) {
            return;
        }

        warning(sprintf(
            '~/.aws/credentials has a [%s] section — static keys there SHADOW credential_process, so the profile would silently keep using them.',
            $profile,
        ));

        if (confirm('Remove that section now?', default: true)) {
            $credentials->removeSection($profile);
            info('Removed. The long-lived key now lives only in your credential source.');
        } else {
            warning('Left in place — the credential_process setup will NOT take effect until it is removed.');
        }
    }

    /**
     * Point this app at the profile: YOLO_{ENV}_AWS_PROFILE in the local .env
     * (replaced in place when present, appended otherwise).
     */
    protected function writeEnvProfile(string $profile): void
    {
        $key = Helpers::keyedEnvName('AWS_PROFILE');
        $line = sprintf('%s=%s', $key, $profile);
        $path = Paths::base('.env');
        $contents = file_exists($path) ? file_get_contents($path) : '';

        $contents = preg_match(sprintf('/^%s=.*$/m', preg_quote((string) $key, '/')), $contents) === 1
            ? preg_replace_callback(sprintf('/^%s=.*$/m', preg_quote((string) $key, '/')), fn (): string => $line, $contents)
            : rtrim($contents, "\n") . ($contents === '' ? '' : "\n") . $line . "\n";

        file_put_contents($path, $contents);

        info(sprintf('Set %s in .env.', $line));
    }

    /**
     * Prove the whole chain — helper, item, profile, region — with a live STS
     * call through the AWS CLI, exactly as every future command will use it,
     * and hold the result against the manifest's account so a wrong-account
     * key fails now instead of at the first sync.
     */
    protected function verify(string $profile): bool
    {
        $process = new Process(['aws', 'sts', 'get-caller-identity', '--profile', $profile, '--output', 'json']);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            error(sprintf('Verification failed: %s', trim($process->getErrorOutput())));

            return false;
        }

        $identity = json_decode($process->getOutput(), true);

        if (($identity['Account'] ?? null) !== (string) Manifest::get('account-id')) {
            error(sprintf(
                'Account mismatch: the credentials resolve to %s but yolo.yml declares %s — wrong key for this environment.',
                $identity['Account'] ?? 'unknown',
                Manifest::get('account-id'),
            ));

            return false;
        }

        if (! $this->enforceMfaPosture($this->userHasMfaDevice($profile), $this->itemHasTotp)) {
            return false;
        }

        outro(sprintf('Authenticated as %s — this machine is ready for %s. 🚀', $identity['Arn'], Helpers::environment()));

        return true;
    }

    /**
     * Whether the IAM user behind the profile has an MFA device registered —
     * null when the check itself fails (iam:ListMFADevices not granted). Uses
     * the just-verified profile, so it exercises the same session every other
     * command will.
     */
    protected function userHasMfaDevice(string $profile): ?bool
    {
        $process = new Process(['aws', 'iam', 'list-mfa-devices', '--profile', $profile, '--query', 'MFADevices[0].SerialNumber', '--output', 'text']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput()) !== 'None';
    }

    /**
     * MFA is invisible in a green verify — the helper warns on stderr when it
     * mints without MFA, but a successful credential_process never surfaces
     * that. And it's a hard requirement: every YOLO tier role's trust demands
     * `aws:MultiFactorAuthPresent`, so a session minted without MFA can't
     * assume anything. Fail here, at setup, with the missing half named —
     * never at the first real command with an opaque AccessDenied.
     */
    protected function enforceMfaPosture(?bool $deviceRegistered, ?bool $itemHasTotp): bool
    {
        if ($deviceRegistered === false) {
            error('No MFA device is registered on this IAM user. Every YOLO tier requires MFA to assume — register a device in IAM, add its TOTP to your credential source, then re-run configure.');

            return false;
        }

        if ($deviceRegistered === null) {
            warning('MFA posture unknown — iam:ListMFADevices was denied for this user. Grant it on self (a standard force-MFA policy carves it out). Every YOLO tier requires MFA, so commands will refuse if sessions mint without it.');

            return true;
        }

        if ($itemHasTotp === false) {
            error('An MFA device is registered, but the 1Password item has no one-time-password field — sessions will mint WITHOUT MFA, and every YOLO tier requires it. Seed a TOTP field from the IAM device, then re-run configure.');

            return false;
        }

        info($itemHasTotp === true
            ? 'MFA device registered and TOTP present — sessions will be MFA-forwarded.'
            : 'MFA device registered — every YOLO tier requires MFA, so make sure your credential_process forwards a TOTP.');

        return true;
    }

    protected function awsConfigFile(): SharedIniFile
    {
        return new SharedIniFile($this->awsDirectory() . '/config', prefixedSections: true);
    }

    /**
     * Overridable via the container so tests never touch a real ~/.aws.
     */
    protected function awsDirectory(): string
    {
        return Helpers::app()->bound('awsDirectory')
            ? Helpers::app()->get('awsDirectory')
            : ($_SERVER['HOME'] ?? getenv('HOME')) . '/.aws';
    }

    protected function localBinDirectory(): string
    {
        return Helpers::app()->bound('localBinDirectory')
            ? Helpers::app()->get('localBinDirectory')
            : ($_SERVER['HOME'] ?? getenv('HOME')) . '/.local/bin';
    }
}
