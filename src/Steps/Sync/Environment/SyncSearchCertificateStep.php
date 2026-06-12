<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The certificate for the environment's search host: a DNS-validated cert on
 * the env domain ({domain} + *.{domain}, which covers search.{domain}),
 * attached to the shared :443 listener via SNI. When the env domain is also
 * an app's apex the cert already exists and this step just guarantees the
 * attachment — diff-first against DescribeListenerCertificates (the
 * default-cert-only DescribeListeners trap), so an attached cert plans clean.
 *
 * Teardown deliberately leaves the certificate and its attachment: the cert
 * may serve an app sharing the domain, and an unused SNI attachment costs
 * nothing — cert lifecycle is the domain owner's, not the search service's.
 */
class SyncSearchCertificateStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        Typesense::requireSearchHost();

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $certificate = new SslCertificate((string) EnvManifest::get('domain'));
        $summary = $certificate->find();

        if ($summary === null) {
            $this->recordChange(Change::make('search certificate', 'absent', 'requested + DNS-validated'));

            if ($dryRun) {
                return StepResult::WOULD_CREATE;
            }

            $certificate->validate($certificate->request());

            return StepResult::CREATED;
        }

        if ($summary['Status'] === 'PENDING_VALIDATION') {
            $this->recordChange(Change::make('search certificate', 'pending validation', 'validated'));

            if (! $dryRun) {
                $certificate->validate($summary['CertificateArn']);
            }

            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return $this->attach($summary['CertificateArn'], $dryRun);
    }

    /**
     * Ensure the issued cert rides the shared :443 listener via SNI —
     * diff-first against DescribeListenerCertificates so an already-attached
     * cert reports clean. On a greenfield plan the listener doesn't exist yet;
     * record the pending attachment without resolving it.
     */
    protected function attach(string $certificateArn, bool $dryRun): StepResult
    {
        try {
            $listenerArn = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443)['ListenerArn'];
        } catch (ResourceDoesNotExistException) {
            // The :443 listener is bootstrapped by the first app's cert (the
            // app tier) — report the pending attachment and let the next sync
            // converge.
            $this->recordChange(Change::make('search certificate attachment', null, 'attached (:443 listener pending)'));

            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SKIPPED;
        }

        try {
            ElbV2::listenerCertificate($listenerArn, $certificateArn);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('search certificate attachment', 'absent', 'attached'));

            if (! $dryRun) {
                Aws::elasticLoadBalancingV2()->addListenerCertificates([
                    'ListenerArn' => $listenerArn,
                    'Certificates' => [['CertificateArn' => $certificateArn]],
                ]);
            }

            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }
    }
}
