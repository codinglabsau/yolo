<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withCache(__DIR__ . '/.rector.cache')
    // Pint owns formatting — Rector stays on semantic transforms only, so the two
    // never fight (no CODING_STYLE / import-ordering rules here). Run order is
    // Rector → Pint → PHPStan.
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    );
