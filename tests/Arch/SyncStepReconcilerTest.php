<?php

use Codinglabs\Yolo\Steps\Sync\Account\SyncGithubOidcProviderStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsEventBridgeTargetStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncInternetGatewayAttachmentStep;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncSslCertificateStep as SoloSslCertificateStep;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\AttachSslCertificateToLoadBalancerListenerStep;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncSslCertificateStep as TenantSslCertificateStep;

/**
 * Every sync step that mutates AWS must be able to record drift into the plan: a
 * write that records no Change is invisible to the plan pass and pruned before
 * apply — the LPX-646 / #95 class of bug. The structural floor is that every
 * concrete `Steps\Sync` step exposes `changes()` (via RecordsChanges, directly or
 * through SynchronisesResource / inheritance).
 *
 * Steps that gate every write on a live existence/state check — and so return
 * SYNCED without writing when already provisioned — never write invisibly and are
 * exempted below. Each exemption is a deliberate decision: a new step that
 * bypasses the change machinery fails this test until it's either fixed or
 * consciously added here with a justification.
 */
it('every sync step can record drift into the plan, or is an explicit exemption', function (): void {
    // Existence/state-diff steps: they check live state first and return SYNCED
    // (without writing) when already provisioned, so a clean sync stays quiet.
    $existenceDiff = [
        SyncGithubOidcProviderStep::class,        // account-level singleton: exists ⇒ done
        SoloSslCertificateStep::class,            // ACM cert lifecycle (request/validate by state)
        TenantSslCertificateStep::class,          // same, per tenant
        SyncIvsEventBridgeTargetStep::class,      // compares the live target ARN before putTargets
        SyncInternetGatewayAttachmentStep::class, // checks the attachment state before attaching
    ];

    // Known debt — does NOT belong here long-term. Tracked in LPX-669: it returns
    // WOULD_SYNC on every plan pass regardless of drift, so a multi-tenant sync
    // never reaches "Already in sync". Remove once it's made diff-first.
    $knownOverReporters = [
        AttachSslCertificateToLoadBalancerListenerStep::class,
    ];

    $exempt = [...$existenceDiff, ...$knownOverReporters];

    $root = dirname(__DIR__, 2) . '/src';
    $syncRoot = $root . '/Steps/Sync';

    $offenders = [];
    $examined = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($syncRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr((string) $file->getPathname(), strlen($root) + 1);
        $class = 'Codinglabs\\Yolo\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }
        if (in_array($class, $exempt, true)) {
            continue;
        }

        $examined++;

        if (! method_exists($class, 'changes')) {
            $offenders[] = $class;
        }
    }

    // Guard against a vacuous pass: if the path resolution ever breaks, the loop
    // examines nothing and offenders is trivially empty. There are dozens of sync steps.
    expect($examined)->toBeGreaterThan(40);

    // A non-empty list means a sync step mutates AWS but can't surface that work in
    // the plan — use RecordsChanges (directly or via SynchronisesResource), or add
    // it to the exemption list above with a justification.
    expect($offenders)->toBe([]);
});
