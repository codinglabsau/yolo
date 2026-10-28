<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Credentials\Totp;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Resources\Iam\AgentUser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Credentials\AgentCredentials;
use Codinglabs\Yolo\Credentials\OnePasswordVault;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

/**
 * Cuts the agent user a fresh access key (enrolling its MFA device on the first
 * run) and writes key + TOTP seed into the shared vault item — the only place they
 * ever live. The previous key is deleted only once the new item is written, so a
 * failed write never strands the agents.
 *
 * Admin-tier: the per-run MFA prompt is the human proving they mean to reissue a
 * credential the whole team's agents share.
 */
class AgentRotateCommand extends Command implements AdminCommand
{
    protected function configure(): void
    {
        $this
            ->setName('agent:rotate')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('vault', null, InputOption::VALUE_REQUIRED, '1Password vault holding the agent item', AgentCredentials::DEFAULT_VAULT)
            ->addOption('reset-mfa', null, InputOption::VALUE_NONE, 'Replace the agent\'s MFA device (needed when its seed is no longer in the vault)')
            ->setDescription('Issue the coding-agent user a fresh access key and store it in the shared 1Password item');
    }

    public function handle(): int
    {
        $user = new AgentUser();
        $vault = (string) $this->option('vault');
        $title = AgentCredentials::itemTitle();

        intro(sprintf('Rotate the coding-agent key — %s', Helpers::environment()));

        if (! $user->exists()) {
            error(sprintf('%s does not exist yet — run `yolo sync:environment %s` first.', $user->name(), Helpers::environment()));

            return self::FAILURE;
        }

        if (! $this->onePasswordCliInstalled()) {
            error('The 1Password CLI (op) is required: brew install --cask 1password-cli');

            return self::FAILURE;
        }

        $onePassword = $this->onePassword();
        $previous = $onePassword->item($title, $vault);
        $seed = $this->mfaSeed($user, $previous);

        if ($seed === null) {
            return self::FAILURE;
        }

        $staleKeys = IamClient::accessKeys($user->name());

        // IAM allows two keys per user; with two present, the older one predates the
        // last rotation and nothing should still be minting from it.
        if (count($staleKeys) >= 2) {
            $oldest = collect($staleKeys)->sortBy('CreateDate')->first();
            Aws::iam()->deleteAccessKey(['UserName' => $user->name(), 'AccessKeyId' => $oldest['AccessKeyId']]);
            $staleKeys = array_values(array_filter($staleKeys, fn (array $key): bool => $key['AccessKeyId'] !== $oldest['AccessKeyId']));
        }

        $key = Aws::iam()->createAccessKey(['UserName' => $user->name()])['AccessKey'];

        try {
            $onePassword->create($vault, $this->item($title, $user, $key['AccessKeyId'], $key['SecretAccessKey'], $seed));
        } catch (\Throwable $e) {
            Aws::iam()->deleteAccessKey(['UserName' => $user->name(), 'AccessKeyId' => $key['AccessKeyId']]);
            error($e->getMessage());
            warning('The new key was deleted again; the agents keep running on the old one.');

            return self::FAILURE;
        }

        if ($previous !== null && isset($previous['id'])) {
            $onePassword->archive((string) $previous['id'], $vault);
        }

        foreach ($staleKeys as $stale) {
            Aws::iam()->deleteAccessKey(['UserName' => $user->name(), 'AccessKeyId' => $stale['AccessKeyId']]);
        }

        info(sprintf("Wrote '%s' to the %s vault.", $title, $vault));
        note("Share the vault with the environment's observers — vault access is what lets a developer's agent run.\nSessions minted from the old key keep working until they expire (at most an hour).");
        outro(sprintf('Developers run `yolo configure %s --agent` to point their agents at it.', Helpers::environment()));

        return self::SUCCESS;
    }

    /**
     * An enrolled device's seed is only recoverable from the vault item, so with no
     * item (or no TOTP in it) the device has to be replaced — and that is never done
     * implicitly.
     *
     * @param  array<string, mixed>|null  $previous
     */
    protected function mfaSeed(AgentUser $user, ?array $previous): ?string
    {
        $devices = IamClient::mfaDevices($user->name());
        $stored = $this->storedSeed($previous);

        if ($devices !== [] && ! $this->option('reset-mfa')) {
            if ($stored === null) {
                error(sprintf(
                    "%s has an MFA device, but its seed isn't in the vault item. Re-run with --reset-mfa to replace the device.",
                    $user->name(),
                ));
            }

            return $stored;
        }

        foreach ($devices as $device) {
            Aws::iam()->deactivateMFADevice(['UserName' => $user->name(), 'SerialNumber' => $device['SerialNumber']]);
            Aws::iam()->deleteVirtualMFADevice(['SerialNumber' => $device['SerialNumber']]);
        }

        $device = Aws::iam()->createVirtualMFADevice([
            'VirtualMFADeviceName' => $user->mfaDeviceName(),
        ])['VirtualMFADevice'];

        $seed = (string) $device['Base32StringSeed'];
        $now = $this->now();

        // Two consecutive codes, the previous step and the current one, so neither
        // is ahead of AWS's clock.
        Aws::iam()->enableMFADevice([
            'UserName' => $user->name(),
            'SerialNumber' => $device['SerialNumber'],
            'AuthenticationCode1' => Totp::code($seed, $now - Totp::STEP),
            'AuthenticationCode2' => Totp::code($seed, $now),
        ]);

        info(sprintf('Enrolled an MFA device on %s.', $user->name()));

        return $seed;
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    protected function storedSeed(?array $item): ?string
    {
        $field = collect($item['fields'] ?? [])->first(fn (array $field): bool => ($field['type'] ?? null) === 'OTP');

        $seed = $field === null ? '' : Totp::seedFrom((string) ($field['value'] ?? ''));

        return $seed === '' ? null : $seed;
    }

    /**
     * The field labels are the credential helper's contract.
     *
     * @return array<string, mixed>
     */
    protected function item(string $title, AgentUser $user, string $accessKeyId, string $secretAccessKey, string $seed): array
    {
        return [
            'title' => $title,
            'category' => 'LOGIN',
            'fields' => [
                ['id' => 'username', 'type' => 'STRING', 'purpose' => 'USERNAME', 'label' => 'username', 'value' => $user->name()],
                ['id' => 'aws_access_key_id', 'type' => 'STRING', 'label' => 'aws_access_key_id', 'value' => $accessKeyId],
                ['id' => 'aws_secret_access_key', 'type' => 'CONCEALED', 'label' => 'aws_secret_access_key', 'value' => $secretAccessKey],
                ['id' => 'one_time_password', 'type' => 'OTP', 'label' => 'one-time password', 'value' => Totp::uri($seed, $user->name())],
            ],
        ];
    }

    /**
     * Resolved through the container so tests never touch a real vault.
     */
    protected function onePassword(): OnePasswordVault
    {
        return Helpers::app()->make(OnePasswordVault::class);
    }

    protected function onePasswordCliInstalled(): bool
    {
        return (new ExecutableFinder())->find('op') !== null;
    }

    protected function now(): int
    {
        return time();
    }
}
