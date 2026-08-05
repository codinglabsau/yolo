<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Tenant;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Detaches one tenant's certificate from this environment's `:443` listener SNI
 * set — the per-tenant twin of
 * {@see \Codinglabs\Yolo\Steps\Destroy\App\DetachSslCertificateStep}.
 *
 * The ACM certificate itself is never deleted, and neither is the tenant's hosted
 * zone: both are domain-level infrastructure belonging to the tenant, not to this
 * app's deployment of it. Tearing the app down withdraws YOLO's *use* of them and
 * leaves the tenant's domain intact — which is what makes absorbing a
 * pre-existing custom domain safe to undo.
 */
class DetachSslCertificateStep extends TenantStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain']) || Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        $summary = (new SslCertificate($this->config['certificate-domain'], $this->config['apex']))->find();

        if ($summary === null) {
            return StepResult::SKIPPED;
        }

        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make(
            sprintf('%s SSL certificate (ACM cert kept — never deleted)', $this->config['certificate-domain']),
            "attached to this app's HTTPS listener",
            'detached',
        ));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        try {
            Aws::elasticLoadBalancingV2()->removeListenerCertificates([
                'ListenerArn' => $listener['ListenerArn'],
                'Certificates' => [['CertificateArn' => $summary['CertificateArn']]],
            ]);
        } catch (AwsException) {
            // The listener's default certificate can't be removed this way (AWS
            // rejects it) and an already-detached one is a no-op — both are fine,
            // since the certificate is kept regardless.
        }

        return StepResult::DELETED;
    }
}
