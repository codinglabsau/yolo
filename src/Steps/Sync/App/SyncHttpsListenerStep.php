<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\HttpsListener;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpsListenerStep implements ExecutesWebStep
{
    use ResolvesHttpsListener;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $certificate = $this->defaultCertificate();

        if ($certificate === null) {
            // Nothing issued yet. On the plan pass — which runs before anything is
            // created — that's the normal greenfield state: this app's certificate is
            // requested AND validated to ISSUED earlier in the same apply, so the
            // listener is creatable by the time apply reaches here. Report it pending
            // so the step survives; a bare SKIPPED is pruned from the apply pass (two-
            // pass contract), leaving no listener, no forward rule hanging off it, and
            // an unattached target group that ECS CreateService then rejects.
            if ((bool) Arr::get($options, 'dry-run') && $this->certificateWillBeIssuedThisSync()) {
                // The listener is env-scope: a sibling app may already have created it,
                // in which case this app's certificate only needs attaching to it.
                if ($this->httpsListener() !== null) {
                    $this->recordChange(Change::make('listener certificate', 'absent', 'attached'));

                    return StepResult::WOULD_SYNC;
                }

                $this->recordChange(Change::make('https listener', null, 'created'));

                return StepResult::WOULD_CREATE;
            }

            return StepResult::SKIPPED;
        }

        $listener = new HttpsListener($certificate);

        // Cert-attachment is orchestration, not part of the resource's identity.
        // Record the change before the dry-run guard so it shows in the plan and
        // survives to apply.
        if ($listener->exists() && ! $this->listenerHasCertificate($listener->arn(), $certificate['CertificateArn'])) {
            $this->recordChange(Change::make('listener certificate', 'absent', 'attached'));

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::elasticLoadBalancingV2()->addListenerCertificates([
                'ListenerArn' => $listener->arn(),
                'Certificates' => [
                    ['CertificateArn' => $certificate['CertificateArn']],
                ],
            ]);
        }

        return $this->syncResource($listener, $options);
    }

    /**
     * The certificate the `:443` listener is created with. ALB requires exactly
     * one *default* certificate, which only ever serves requests whose SNI matches
     * nothing attached — every real host is matched by its own SNI certificate,
     * added by this step or by the per-tenant attach step.
     *
     * The app's own domain wins when it has one. A tenanted app that doesn't
     * (every tenant brings its own domain) falls back to its tenants, sorted so
     * the choice is stable — picking by manifest order would silently re-create
     * the listener under a different default when tenants are reordered.
     *
     * Null when nothing is issued yet: the listener can't be created without a
     * certificate, and the steps that hang rules off it plan as pending until it
     * can be ({@see ResolvesHttpsListener}).
     *
     * @return array<string, mixed>|null
     */
    protected function defaultCertificate(): ?array
    {
        foreach ($this->certificateDomains() as $domain) {
            try {
                $certificate = Acm::certificate($domain);
            } catch (ResourceDoesNotExistException) {
                continue;
            }

            if ($certificate['Status'] === 'ISSUED') {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * Whether any candidate certificate will be issued in time to be the
     * listener's default — the plan-pass discriminator between "not issued YET"
     * (the certificate steps run earlier in this same apply, so survive to apply)
     * and "won't be issuable at all this run" (genuinely defer).
     */
    protected function certificateWillBeIssuedThisSync(): bool
    {
        foreach ($this->certificateDomains() as $domain) {
            if ($this->certificateWillBeIssued($domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate domains for the default certificate, in preference order.
     *
     * @return array<int, string>
     */
    protected function certificateDomains(): array
    {
        return [
            ...Manifest::hasDomain() ? [Manifest::certificateDomain()] : [],
            ...collect(Manifest::tenants())
                ->filter(fn (array $config): bool => isset($config['apex']))
                ->map(fn (array $config): string => (string) $config['apex'])
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
