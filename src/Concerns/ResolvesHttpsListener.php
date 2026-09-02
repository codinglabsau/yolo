<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The `:443` listener is created by the first domained app's sync, so on the plan
 * pass it may not exist yet. A rule step that SKIPPED on that would be pruned from
 * apply and leave the target group unattached — callers pair httpsListener() with
 * httpsListenerWillBeCreatedThisSync() to tell "not yet" (report pending) from
 * "not this run" (genuinely defer).
 */
trait ResolvesHttpsListener
{
    /**
     * @return array<string, mixed>|null
     */
    protected function httpsListener(): ?array
    {
        try {
            return ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * An app's cert hangs off the shared listener as an SNI cert, not the default,
     * so inspecting the default would read every non-creator app's cert as missing.
     */
    protected function listenerHasCertificate(string $listenerArn, string $certificateArn): bool
    {
        try {
            ElbV2::listenerCertificate($listenerArn, $certificateArn);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * SyncHttpsListenerStep creates the listener as soon as a certificate is
     * available to be its default, so the discriminator is the certificate.
     */
    protected function httpsListenerWillBeCreatedThisSync(): bool
    {
        return $this->certificateWillBeIssued(Manifest::certificateDomain());
    }

    /**
     * Absent or PENDING_VALIDATION counts as yes: the certificate step runs earlier
     * in the SAME apply and blocks until ACM issues it. Asking "is it issued right
     * now" would read a greenfield run as a no and prune the caller from apply.
     * Any other status (FAILED, EXPIRED, REVOKED, VALIDATION_TIMED_OUT) is terminal
     * for this run, so the caller should genuinely defer.
     */
    protected function certificateWillBeIssued(string $domain): bool
    {
        try {
            return in_array(Acm::certificate($domain)['Status'], ['ISSUED', 'PENDING_VALIDATION'], true);
        } catch (ResourceDoesNotExistException) {
            return true;
        }
    }
}
