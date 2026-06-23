<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Withdraws this app's use of its TLS certificate — and ONLY that. The ACM
 * certificate itself is NEVER deleted: like the hosted zone, it's domain-level
 * infrastructure that can outlive any single environment. ACM addresses a cert by
 * domain name with no environment scoping, so a sibling environment serving the
 * same domain (a trial on `staging.example.com` alongside prod on `example.com`)
 * may hold a certificate for it too — and a domain-keyed lookup can't tell them
 * apart. Deleting one could break another environment's HTTPS, so destroy:app
 * never deletes the cert (certs are free to leave standing).
 *
 * All this step does is detach the cert from THIS environment's :443 listener SNI
 * set — the app's slice on the shared, env-scoped listener — mirroring how
 * {@see WithdrawAppDnsRecordsStep} withdraws the app's records but keeps the zone.
 * An SNI cert detaches cleanly; the listener's single default cert can't be
 * removed this way (AWS rejects it) and is tolerated — it's freed when
 * `yolo destroy:environment` removes the listener.
 */
class DetachSslCertificateStep implements ExecutesWebStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $certificate = new SslCertificate(Manifest::apex());
        $summary = $certificate->find();

        if ($summary === null) {
            return StepResult::SKIPPED;
        }

        // Nothing to detach from once this env's listener is gone — but the ACM
        // cert is kept either way, so this is a clean skip, not pending work.
        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make(
            sprintf('%s SSL certificate (ACM cert kept — never deleted)', Manifest::apex()),
            'attached to this app\'s HTTPS listener',
            'detached',
        ));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $this->detachFromListener($listener['ListenerArn'], $summary['CertificateArn']);

        return StepResult::DELETED;
    }

    /**
     * Remove the certificate from this environment's :443 listener SNI set. The
     * listener's default certificate can't be removed this way (AWS rejects it)
     * and a not-attached cert is a no-op — both are tolerated. The ACM cert is
     * never deleted here, so a still-referenced cert degrades safely.
     */
    protected function detachFromListener(string $listenerArn, string $certificateArn): void
    {
        try {
            Aws::elasticLoadBalancingV2()->removeListenerCertificates([
                'ListenerArn' => $listenerArn,
                'Certificates' => [['CertificateArn' => $certificateArn]],
            ]);
        } catch (AwsException) {
            // Default cert or already detached — leave it; the ACM cert is kept.
        }
    }
}
