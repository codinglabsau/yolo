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
 * The certificate for the environment's search host plus the shared :443
 * listener it rides. An env-backed service owns its own public ingress — it may
 * run on a domain no app shares — so it can't wait on an app to bring up HTTPS:
 *
 *  - it asserts an apex + wildcard cert on the env domain ({domain} + *.{domain},
 *    which covers search.{domain}), reusing an app's existing cert when the
 *    domain is shared and minting one when the domain is new; then
 *  - it guarantees the shared :443 listener exists, bootstrapping it from this
 *    cert when no app has yet (create-if-missing keeps a single writer: an app
 *    that later needs :443 finds it and only SNI-attaches its own cert); and
 *  - it makes sure this cert is on the listener — as the listener default when it
 *    bootstraps, or via SNI (diff-first against DescribeListenerCertificates, the
 *    default-cert-only DescribeListeners trap) when an app got there first.
 *
 * Teardown deliberately leaves the certificate and listener: both may serve an
 * app sharing the domain, and an idle SNI attachment costs nothing.
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

        return $this->ensureHttpsListener($summary['CertificateArn'], $dryRun);
    }

    /**
     * Guarantee the issued cert serves the shared :443 listener, bootstrapping
     * the listener itself when no app has. Three states: the ALB isn't up yet
     * (provisioned later in the env sync) -> report the pending listener and let
     * the next sync converge; no :443 listener -> create it from this cert; the
     * listener exists -> ensure this cert rides it via SNI (diff-first, so an
     * already-attached cert reports clean).
     */
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
            // No :443 listener yet — the service brings it up from its own
            // (apex + wildcard) cert rather than waiting on an app.
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
