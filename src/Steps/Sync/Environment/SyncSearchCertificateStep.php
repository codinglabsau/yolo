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
use Codinglabs\Yolo\Resources\ElbV2\HttpsListener;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * An env-backed service owns its own public ingress — it may run on a domain no
 * app shares — so it can't wait on an app to bring up HTTPS: it asserts an apex
 * + wildcard cert on the env domain and bootstraps the shared :443 listener
 * from it when no app has yet (create-if-missing keeps a single writer; an app
 * that later needs :443 only SNI-attaches). Attachment is diffed against
 * DescribeListenerCertificates — DescribeListeners shows the default cert only.
 *
 * Teardown leaves the certificate and listener: both may serve an app sharing
 * the domain, and an idle SNI attachment costs nothing.
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
        // The environment domain names its own hosted zone, so the certificate and
        // its validation record share one zone.
        $certificate = new SslCertificate($domain = (string) EnvManifest::get('domain'), $domain);
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

        return $this->ensureHttpsListener($summary['CertificateArn'], $dryRun);
    }

    protected function ensureHttpsListener(string $certificateArn, bool $dryRun): StepResult
    {
        try {
            $loadBalancerArn = (new LoadBalancer())->arn();
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('search :443 listener', null, 'created (load balancer pending)'));

            return $dryRun ? StepResult::WOULD_CREATE : StepResult::SKIPPED;
        }

        try {
            $listenerArn = ElbV2::listenerOnPort($loadBalancerArn, 443)['ListenerArn'];
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('search :443 listener', 'absent', 'created'));

            if (! $dryRun) {
                (new HttpsListener(['CertificateArn' => $certificateArn]))->create();
            }

            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
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
