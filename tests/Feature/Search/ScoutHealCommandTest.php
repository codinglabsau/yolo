<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Search\Product;
use Tests\Fixtures\Search\Voucher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Codinglabs\Yolo\Runtime\Search\ReimportSearchModel;

uses(SearchTestbenchCase::class);

beforeEach(function (): void {
    Schema::create('products', function ($table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

it('refills a missing collection through Scout\'s own queued range imports', function (): void {
    Bus::fake();
    DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->artisan('scout:heal')->assertExitCode(0);

    // Scout's machinery does the refill — ID-range jobs fan across the
    // workers, and the engine recreates the collection with the declared
    // schema on the first batch.
    Bus::assertDispatched(MakeRangeSearchable::class, fn (MakeRangeSearchable $job): bool => $job->class === Product::class);
});

it('refills when the index sits empty while the database is not — the wipe signature', function (): void {
    Bus::fake();
    DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);

    Http::fake(fn ($request) => str_contains((string) $request->url(), '/aliases/')
        ? Http::response(['collection_name' => 'test_products_x'])
        : Http::response(['name' => 'test_products_x', 'num_documents' => 0]));

    $this->artisan('scout:heal')->assertExitCode(0);

    Bus::assertDispatched(MakeRangeSearchable::class);
});

it('does not re-queue a refill that is still landing', function (): void {
    Bus::fake();
    DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    // Two ticks while the queue is still chewing through the first refill —
    // Scout's range jobs aren't unique, so the marker is what stops the
    // second tick doubling the queue.
    $this->artisan('scout:heal')->assertExitCode(0);
    $this->artisan('scout:heal')->assertExitCode(0);

    Bus::assertDispatchedTimes(MakeRangeSearchable::class, 1);
});

it('falls back to the alias-swap rebuild job for a non-numeric scout key', function (): void {
    Bus::fake();

    Schema::create('vouchers', function ($table): void {
        $table->string('code')->primary();
        $table->timestamps();
    });
    DB::table('vouchers')->insert(['code' => 'WELCOME10', 'created_at' => now(), 'updated_at' => now()]);

    config()->set('scout.typesense.model-settings', [
        Voucher::class => ['collection-schema' => ['fields' => [['name' => 'code', 'type' => 'string']]]],
    ]);

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->artisan('scout:heal')->assertExitCode(0);

    // scout:queue-import refuses non-numeric keys, so the heal takes the
    // single queued rebuild through the alias-swap engine instead.
    Bus::assertDispatched(ReimportSearchModel::class, fn (ReimportSearchModel $job): bool => $job->modelClass === Voucher::class);
    Bus::assertNotDispatched(MakeRangeSearchable::class);
});

it('warns instead of refilling a searchable model with no declared schema', function (): void {
    Bus::fake();

    config()->set('scout.typesense.model-settings', []);
    config()->set('scout.typesense.client-settings.api_key', 'scoped-key'); // still "configured"

    // Discovery only finds Product via model-settings in this app shape, so
    // pointing the config at a model with no schema simulates the swept-only
    // case: searchable, but nothing to rebuild from.
    config()->set('scout.typesense.model-settings', [Product::class => []]);

    Http::fake();

    $this->artisan('scout:heal')
        ->expectsOutputToContain('declares no Typesense schema')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('leaves a healthy index alone — and an empty index over an empty table is healthy', function (string $fixture): void {
    Bus::fake();

    if ($fixture === 'populated') {
        DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);
        $documents = 1;
    } else {
        $documents = 0; // empty table, empty index — nothing to heal
    }

    Http::fake(fn ($request) => str_contains((string) $request->url(), '/aliases/')
        ? Http::response(['message' => 'Not Found'], 404)
        : Http::response(['name' => 'test_products', 'num_documents' => $documents]));

    $this->artisan('scout:heal')->assertExitCode(0);

    Bus::assertNothingDispatched();
})->with(['populated', 'empty']);

it('clears the dispatch marker once the index is healthy again', function (): void {
    Bus::fake();

    Cache::put('scout:heal:queued:' . Product::class, true, 3600);

    Http::fake(fn ($request) => str_contains((string) $request->url(), '/aliases/')
        ? Http::response(['message' => 'Not Found'], 404)
        : Http::response(['name' => 'test_products', 'num_documents' => 3]));

    $this->artisan('scout:heal')->assertExitCode(0);

    // A healthy pass forgets the marker, so a wipe recurring later gets a
    // fresh refill immediately rather than waiting out the TTL.
    expect(Cache::get('scout:heal:queued:' . Product::class))->toBeNull();
});

it('reports and fails when the index cannot even be inspected, without queueing anything', function (): void {
    Bus::fake();

    // A 401 means the cluster no longer honours this app's key — sync:app's
    // problem; queueing rebuilds against it would just fail noisily.
    Http::fake(['*' => Http::response(['message' => 'Forbidden'], 401)]);

    $this->artisan('scout:heal')->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('skips quietly when another heal pass holds the lock', function (): void {
    Bus::fake();
    Http::fake();

    // Combined-services apps run the scheduler on every web task — the lock,
    // baked into the command, is what keeps a wipe from triggering N refills.
    Cache::lock('scout:heal', 600)->get();

    $this->artisan('scout:heal')->assertExitCode(0);

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('does nothing on an app without Typesense wiring', function (): void {
    Bus::fake();
    Http::fake();

    config()->set('scout.typesense.client-settings', []);

    $this->artisan('scout:heal')->assertExitCode(0);

    Http::assertNothingSent();
});
