<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\ReclaimsNetwork;
use Codinglabs\Yolo\Contracts\PlansSequentially;
use Codinglabs\Yolo\Concerns\ConfirmsDestruction;

use function Laravel\Prompts\error;

/**
 * App → environment → account, so nothing is removed while something still
 * references it. Each scope self-gates on "is anything else still using it": the
 * network shell stays when a database is attached to the VPC (YOLO never deletes a
 * database it doesn't own), and the account-shared OIDC provider stays while any
 * other environment remains (kept and named if that can't be determined).
 */
class DestroyCommand extends SyncSteppedCommand implements PlansSequentially
{
    use ConfirmsDestruction;
    use ReclaimsNetwork;

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('destroy')
            ->setDescription('Permanently tear down an application and its environment (app → environment → account), in reverse-dependency order');
    }

    #[\Override]
    public function handle(): int
    {
        if (($reason = (new DestroyAppCommand())->unsupportedReason()) !== null) {
            error($reason);

            return self::FAILURE;
        }

        // This app is torn down in the same run, so it may still claim the environment.
        $others = array_values(array_diff(Lifecycle::claimingApps(), [Manifest::name()]));

        if ($others !== []) {
            error(sprintf(
                'destroy refuses while other apps still claim %s: %s. Tear each down with `yolo destroy:app %s` first.',
                $this->argument('environment'),
                implode(', ', $others),
                $this->argument('environment'),
            ));

            return self::FAILURE;
        }

        return Destroying::during(fn (): int => parent::handle());
    }

    #[\Override]
    protected function planHeading(): string
    {
        return 'Will destroy';
    }

    #[\Override]
    protected function confirmQuestion(string $environment): string
    {
        return sprintf('Permanently delete this application and the entire %s environment? This cannot be undone.', $environment);
    }

    #[\Override]
    protected function completionVerb(): string
    {
        return 'Destroyed';
    }

    /**
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            // The yolo.yml strip is deferred to the 'manifest' scope so the environment
            // teardown can still read the account/region out of the manifest.
            'app' => array_values(array_filter(
                (new DestroyAppCommand())->scopes()['app'],
                fn (string $step): bool => $step !== Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class,
            )),
            'environment' => [
                ...DestroyEnvironmentCommand::tierASteps(),
                ...$this->networkSteps(),
                ...DestroyEnvironmentCommand::iamTierTeardownSteps(),
            ],
            'account' => [Steps\Destroy\Account\TeardownGithubOidcProviderStep::class],
            'manifest' => [Steps\Destroy\Environment\RemoveEnvironmentFromManifestStep::class],
        ];
    }

    /**
     * @return array<int, string>
     */
    #[\Override]
    public function warnings(): array
    {
        return $this->networkWarnings();
    }

    /**
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return $this->liveDatabases();
    }
}
