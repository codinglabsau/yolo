<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class AttachSslCertificateToLoadBalancerListenerStep extends TenantStep
{
    use RecordsChanges;
    use ResolvesHttpsListener;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        // A tenant already served by the app's own certificate and rule (a subdomain
        // under `wildcard-subdomains`) needs nothing of its own here.
        if (Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        // The plan pass reads both dependencies tolerantly (null when they aren't up
        // yet); the apply pass resolves them strictly, since by then the tenant's
        // certificate and the `:443` listener are provisioned earlier in the same run
        // and their absence is a real failure.
        $certificate = $dryRun ? $this->issuedCertificate() : $this->awaitIssuedCertificate();
        $listener = $dryRun ? $this->httpsListener() : ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);

        // Plan-pass only: a dependency that doesn't exist yet means the attach is
        // pending work, not "nothing to do". Reporting it keeps the step in the apply
        // pass — a bare SKIPPED is pruned, so the certificate would never attach.
        if ($certificate === null || $listener === null) {
            $this->recordChange(Change::make('listener certificate', null, 'attached'));

            return StepResult::WOULD_SYNC;
        }

        if ($this->listenerHasCertificate($listener['ListenerArn'], $certificate['CertificateArn'])) {
            return StepResult::SYNCED;
        }

        // Recorded before the dry-run guard so the drift shows in the plan and the
        // step survives to apply.
        $this->recordChange(Change::make('listener certificate', 'absent', 'attached'));

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        Aws::elasticLoadBalancingV2()->addListenerCertificates([
            'ListenerArn' => $listener['ListenerArn'],
            'Certificates' => [
                ['CertificateArn' => $certificate['CertificateArn']],
            ],
        ]);

        return StepResult::SYNCED;
    }

    /**
     * The tenant's certificate if it's issued, else null — a certificate that hasn't
     * been requested yet, or is still validating, isn't attachable.
     *
     * @return array<string, mixed>|null
     */
    protected function issuedCertificate(): ?array
    {
        try {
            $certificate = Acm::certificate($this->config['certificate-domain']);
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        return $certificate['Status'] === 'ISSUED' ? $certificate : null;
    }

    /**
     * The tenant's certificate, blocking until ACM reports it issued.
     *
     * @return array<string, mixed>
     */
    protected function awaitIssuedCertificate(): array
    {
        $certificate = Acm::certificate($this->config['certificate-domain']);

        while ($certificate['Status'] !== 'ISSUED') {
            // take a little snooze until the certificate is issued
            sleep(2);

            $certificate = Acm::certificate($this->config['certificate-domain']);
        }

        return $certificate;
    }
}
