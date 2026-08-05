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
     * the rule steps. It's created by SyncHttpsListenerStep gated on this app's
     * cert being ISSUED, so that exact condition is the discriminator: an issued
     * cert means the listener is created earlier in this same apply (so the rule
     * should plan as pending); no issued cert means the listener won't be created
     * this run either (so the rule genuinely defers to a later sync).
     */
    protected function httpsListenerWillBeCreatedThisSync(): bool
    {
        return $this->certificateIsIssued(Manifest::certificateDomain());
    }

    /**
     * Whether a domain's certificate exists and is issued. An unissued or absent
     * certificate is the discriminator the rule steps gate on: the listener is
     * only created once a certificate is available to be its default, so no
     * certificate means no listener this run either.
     */
    protected function certificateIsIssued(string $domain): bool
    {
        try {
            return Acm::certificate($domain)['Status'] === 'ISSUED';
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }
}
