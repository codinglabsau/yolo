<?php

declare(strict_types=1);

/**
 * YOLO must NEVER delete an RDS database — not the instance, the cluster, or any
 * snapshot. The network shell around it (VPC, subnets, the RDS *subnet group* and
 * security group) is YOLO's to reclaim, but the database living in it never is.
 *
 * This is a source-level tripwire: no destructive RDS SDK call may appear anywhere
 * in src/, so the capability can never be introduced — by us or a refactor — without
 * a deliberate, reviewed change to this list. It complements the type-level
 * guarantee that the database is never modelled as a deletable resource.
 */
it('never calls a destructive RDS operation anywhere in src', function (): void {
    $forbidden = [
        'deleteDBInstance',
        'deleteDBCluster',
        'deleteDBSnapshot',
        'deleteDBClusterSnapshot',
        'deleteGlobalCluster',
        'deleteDBInstanceAutomatedBackup',
    ];

    $src = dirname(__DIR__, 2) . '/src';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach ($forbidden as $method) {
            if (str_contains($contents, $method)) {
                $offenders[] = sprintf('%s → %s', $file->getFilename(), $method);
            }
        }
    }

    expect($offenders)->toBe([]);
});
