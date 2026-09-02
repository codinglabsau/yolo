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
            // On a greenfield plan pass this app's certificate is requested AND
            // validated to ISSUED earlier in the same apply — report pending so the
            // step survives; a bare SKIPPED would leave no listener, no forward rule,
            // and an unattached target group that ECS CreateService rejects.
            if ((bool) Arr::get($options, 'dry-run') && $this->certificateWillBeIssuedThisSync()) {
                // The listener is env-scope — a sibling app may already have created it.
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

        // Cert-attachment is orchestration, not the resource's identity — record
        // before the dry-run guard so it survives to apply.
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
     * ALB requires exactly one *default* certificate, which only serves requests
     * whose SNI matches nothing attached — every real host gets its own SNI cert.
     * A tenanted app with no domain of its own falls back to its tenants, sorted
     * so the choice is stable — manifest order would silently re-create the
     * listener under a different default when tenants are reordered.
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
     * The plan-pass discriminator between "not issued YET" (the certificate steps
     * run earlier in this same apply) and "won't be issuable this run" (defer).
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
     * In preference order.
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
