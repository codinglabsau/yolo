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
 * Tears an application and its environment down in one pass — the reverse of
 * `sync`, which builds account → environment → app; destroy runs app → environment
 * (→ account), so nothing is removed while something still references it. One
 * plan → confirm → apply across the scopes, behind a single confirm gate.
 *
 * Everything that belongs to the environment goes, gated only on "is anything else
 * still using it":
 *  - the app's resources, then the environment's compute/edge (Tier A);
 *  - the network shell (Tier B) — unless a database is attached to the VPC, which
 *    keeps it standing (YOLO never deletes a database it doesn't own);
 *  - the account-shared GitHub OIDC provider — but only when no other environment
 *    remains (and it fails safe: if that can't be determined, it's kept and named).
 *
 * Guarded: the app must be a shape `destroy:app` supports, and no OTHER app may
 * still claim the environment (this one is being torn down in the same run). The
 * env-backed services come down via the {@see Destroying} flag, as in
 * `destroy:environment`. Stripping the environment from yolo.yml runs dead last,
 * after the teardown that still needs the manifest's account/region to resolve.
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

        // This app is torn down in the same run, so it's allowed to still claim the
        // environment — but any OTHER live app must be destroyed first.
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
     * App → environment → account → manifest, the reverse of sync's account → env →
     * app. runScopes processes the scopes in this order, so the app is gone before
     * the environment, the environment before the account-shared provider, and the
     * yolo.yml environment block is stripped dead last — after every step that still
     * needs the manifest's account/region to resolve. Each scope is self-gating: the
     * network steps drop out when a database is attached, and the account provider
     * step keeps itself when another environment still exists.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            // Every destroy:app step except its yolo.yml strip, which is deferred to
            // the 'manifest' scope below so the environment teardown can still read
            // the account/region out of the manifest.
            'app' => array_values(array_filter(
                (new DestroyAppCommand())->scopes()['app'],
                fn (string $step): bool => $step !== Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class,
            )),
            'environment' => [
                ...DestroyEnvironmentCommand::tierASteps(),
                ...$this->networkSteps(),
            ],
            // The account-shared OIDC provider, reclaimed only when this is the last
            // environment — the step self-gates on the live yolo:environment tags and
            // keeps itself (named in the summary) otherwise.
            'account' => [Steps\Destroy\Account\TeardownGithubOidcProviderStep::class],
            'manifest' => [Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class],
        ];
    }

    /**
     * The refusal summary: the network-shell line when a database keeps it standing.
     * The account-provider "kept — other environments exist" line is recorded by its
     * own step and surfaces in the same summary.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function warnings(): array
    {
        return $this->networkWarnings();
    }

    /**
     * The databases YOLO will never delete, named in the confirmation banner — the
     * live RDS instances attached to this environment's VPC.
     *
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return $this->liveDatabases();
    }
}
