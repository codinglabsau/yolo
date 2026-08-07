<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared listener resolution for the app-scope steps that hang a rule off the
 * env `:443` listener (the forward rule, the apex/www redirect rule).
 *
 * The listener is an env-scope resource bootstrapped from `sync:app` by
 * exception — the first domained app in a fresh environment creates it. That
 * makes it a classic two-pass-contract trap for the rule steps: on the plan
 * pass (which runs before anything is created) the listener doesn't exist yet,
 * so a naive `listenerOnPort(443)` throws and the step returns SKIPPED — which
 * the runner prunes from the apply pass, so the rule is never created and the
 * target group is left unattached. Callers pair listener() with
 * willBeCreatedThisSync() to tell "not created YET" (report pending so the step
 * survives to apply) from "won't be created at all this run" (genuinely defer).
 */
trait ResolvesHttpsListener
{
    /**
     * The env `:443` listener, or null when it doesn't exist (yet).
     *
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
     * Whether a certificate is already in the listener's SNI certificate list.
     *
     * The list read (DescribeListenerCertificates) is the only honest source: an
     * app's cert hangs off the shared listener as an SNI cert, not as its single
     * default cert, so inspecting the default reads every non-creator app's cert
     * as missing on every sync.
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
     * Whether the `:443` listener will exist by the time the apply pass reaches
     * the rule steps. It's created by SyncHttpsListenerStep as soon as a
     * certificate is available to be its default, so the discriminator is
     * whether this app's certificate will be issued this run.
     */
    protected function httpsListenerWillBeCreatedThisSync(): bool
    {
        return $this->certificateWillBeIssued(Manifest::certificateDomain());
    }

    /**
     * Whether a domain's certificate will be ISSUED by the time the apply pass
     * reaches the caller — the plan-pass question, asked before anything exists.
     *
     * Already ISSUED is the steady-state yes. Absent or PENDING_VALIDATION is
     * equally a yes: the certificate step for this domain runs earlier in the SAME
     * apply and blocks until ACM reports the certificate issued, so a greenfield
     * sync — where no certificate exists at plan time at all — still has one by the
     * time the listener is created. Asking "is it issued right now" instead reads
     * a greenfield run as a no and prunes the caller out of the apply pass.
     *
     * Any other status (FAILED, EXPIRED, REVOKED, VALIDATION_TIMED_OUT) is terminal
     * for this run — the certificate step passes it through untouched — so nothing
     * will make it issuable and the caller should genuinely defer.
     *
     * Only meaningful where a certificate step is wired for the domain, which is
     * exactly where the callers ask it (each already returned SKIPPED otherwise).
     */
    protected function certificateWillBeIssued(string $domain): bool
    {
        try {
            return in_array(Acm::certificate($domain)['Status'], ['ISSUED', 'PENDING_VALIDATION'], true);
        } catch (ResourceDoesNotExistException) {
            // Nothing requested yet — the certificate step requests it this sync.
            return true;
        }
    }
}
