<?php

declare(strict_types=1);

use Tests\Fixtures\Search\Sku;
use Tests\SearchTestbenchCase;
use Tests\Fixtures\Search\Product;
use Tests\Fixtures\Search\Voucher;
use Codinglabs\Yolo\Runtime\Search\SearchableModels;

uses(SearchTestbenchCase::class);

it('sweeps a PSR-4 root for searchable models, resolving a wrapper trait and skipping abstracts', function (): void {
    $swept = SearchableModels::swept(__DIR__ . '/../../Fixtures/Search', 'Tests\\Fixtures\\Search\\');

    // Product and Voucher use Searchable directly; Sku only through the
    // app's own AppSearchable wrapper — class_uses_recursive resolves all
    // three. Widget (not searchable) and BaseSearchableModel (abstract)
    // are out.
    expect($swept)->toBe([Product::class, Sku::class, Voucher::class]);
});

it('reads the configured set from scout.typesense.model-settings', function (): void {
    expect(SearchableModels::configured())->toBe([Product::class]);
});

it('unions the configured set with the app-path sweep', function (): void {
    // The testbench skeleton app has no models, so configured() is the whole
    // set here — the sweep contributes nothing but also breaks nothing.
    expect(SearchableModels::all())->toBe([Product::class]);
});
