<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Search\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Codinglabs\Yolo\Runtime\Search\TypesenseClient;
use Codinglabs\Yolo\Runtime\Search\ZeroDowntimeReimport;

uses(SearchTestbenchCase::class);

beforeEach(function (): void {
    Schema::create('products', function ($table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    // Straight inserts — Scout's observer must not fire during seeding.
    DB::table('products')->insert([
        ['name' => 'anvil', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
        ['name' => 'rocket skates', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
    ]);
});

/**
 * Fake the Typesense API for one reimport run. $alias/$literal shape the
 * pre-existing layout; everything else answers success.
 */
function fakeTypesense(?string $aliasTarget, bool $literalExists): void
{
    Http::fake(function ($request) use ($aliasTarget, $literalExists) {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            str_contains($url, '/aliases/test_products') && $method === 'GET' => $aliasTarget === null
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response(['name' => 'test_products', 'collection_name' => $aliasTarget]),
            str_contains($url, '/documents/import') => Http::response("{\"success\":true}\n{\"success\":true}"),
            str_ends_with($url, '/collections') && $method === 'POST' => Http::response(['name' => 'created']),
            str_contains($url, '/collections/test_products') && $method === 'GET' => $literalExists
                ? Http::response(['name' => 'test_products', 'num_documents' => 2])
                : Http::response(['message' => 'Not Found'], 404),
            default => Http::response([]),
        };
    });
}

/** The recorded request sequence as "METHOD path" strings. */
function recordedCalls(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => $pair[0]->method() . ' ' . parse_url((string) $pair[0]->url(), PHP_URL_PATH) . (parse_url((string) $pair[0]->url(), PHP_URL_QUERY) ? '?' . parse_url((string) $pair[0]->url(), PHP_URL_QUERY) : ''))
        ->all();
}

it('rebuilds a wiped index from nothing: build, import, alias — nothing to delete', function (): void {
    fakeTypesense(aliasTarget: null, literalExists: false);

    $result = (new ZeroDowntimeReimport(new TypesenseClient()))->reimport(Product::class);

    expect($result['alias'])->toBe('test_products')
        ->and($result['collection'])->toStartWith('test_products_')
        ->and($result['documents'])->toBe(2)
        ->and($result['replayed'])->toBe(0);

    $calls = recordedCalls();

    // Schema from model-settings, name overridden to the timestamped build.
    $create = collect(Http::recorded())->first(fn (array $pair): bool => str_ends_with(parse_url((string) $pair[0]->url(), PHP_URL_PATH), '/collections') && $pair[0]->method() === 'POST');
    expect($create[0]->data()['name'])->toBe($result['collection'])
        ->and($create[0]->data()['fields'])->toBe([['name' => 'name', 'type' => 'string']]);

    // Import lands in the temporary collection, never the alias name.
    expect($calls)->toContain(sprintf('POST /collections/%s/documents/import?action=upsert', $result['collection']))
        ->and($calls)->toContain('PUT /aliases/test_products');

    expect(collect($calls)->filter(fn (string $call): bool => str_starts_with($call, 'DELETE')))->toBeEmpty();
});

it('migrates a literal Scout collection to the alias layout: delete the literal, then land the alias', function (): void {
    fakeTypesense(aliasTarget: null, literalExists: true);

    $result = (new ZeroDowntimeReimport(new TypesenseClient()))->reimport(Product::class);

    $calls = recordedCalls();

    // Typesense won't alias over a live collection name, so the literal goes
    // first — after the full import, immediately before the alias, so the
    // serving gap is the milliseconds between these two calls, once ever.
    $import = array_search(sprintf('POST /collections/%s/documents/import?action=upsert', $result['collection']), $calls, true);
    $delete = array_search('DELETE /collections/test_products', $calls, true);
    $alias = array_search('PUT /aliases/test_products', $calls, true);

    expect($import)->toBeLessThan($delete)
        ->and($delete)->toBeLessThan($alias);
});

it('flips the alias atomically on a steady-state rebuild, then reaps the old collection', function (): void {
    fakeTypesense(aliasTarget: 'test_products_20260101000000000', literalExists: false);

    $result = (new ZeroDowntimeReimport(new TypesenseClient()))->reimport(Product::class);

    $calls = recordedCalls();

    $alias = array_search('PUT /aliases/test_products', $calls, true);
    $reap = array_search('DELETE /collections/test_products_20260101000000000', $calls, true);

    // The old collection serves until the flip; it dies only after.
    expect($alias)->toBeLessThan($reap);

    // The literal-layout probe never ran — the alias answered.
    expect($calls)->not->toContain('GET /collections/test_products');

    expect($result['documents'])->toBe(2);
});

it('reaps the half-built collection when the import fails — the live index is untouched', function (): void {
    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            // One rejected document fails the whole build.
            str_contains($url, '/documents/import') => Http::response('{"success":false,"error":"field type mismatch"}'),
            str_ends_with($url, '/collections') && $method === 'POST' => Http::response(['name' => 'created']),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    });

    expect(fn (): array => (new ZeroDowntimeReimport(new TypesenseClient()))->reimport(Product::class))
        ->toThrow(RuntimeException::class, 'field type mismatch');

    // The temporary collection must not survive the failure: the heal loop
    // retries persistent failures every tick, and accumulated partials eat a
    // memory-bound cluster. The alias was never touched.
    $calls = recordedCalls();

    expect(collect($calls)->first(fn (string $call): bool => str_starts_with($call, 'DELETE /collections/test_products_')))->not->toBeNull()
        ->and($calls)->not->toContain('PUT /aliases/test_products');
});

it('replays rows that changed during the build window through Scout', function (): void {
    fakeTypesense(aliasTarget: null, literalExists: false);

    // A row "updated" after the build started — it went to the old
    // collection mid-build and must ride Scout's normal path onto the new
    // one. (The null scout driver keeps the replay off the network; the
    // count proves the window query found it.)
    DB::table('products')->where('name', 'anvil')->update(['updated_at' => now()->addMinute()]);

    $result = (new ZeroDowntimeReimport(new TypesenseClient()))->reimport(Product::class);

    expect($result['replayed'])->toBe(1);
});
