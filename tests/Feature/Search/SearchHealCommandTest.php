<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Search\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Codinglabs\Yolo\Runtime\Search\ReimportSearchModel;

uses(SearchTestbenchCase::class);

beforeEach(function (): void {
    Schema::create('products', function ($table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

it('queues a rebuild when the collection is gone', function (): void {
    Bus::fake();
    DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->artisan('yolo:search:heal')->assertExitCode(0);

    Bus::assertDispatched(ReimportSearchModel::class, fn (ReimportSearchModel $job): bool => $job->modelClass === Product::class);
});

it('queues a rebuild when the index sits empty while the database is not — the wipe signature', function (): void {
    Bus::fake();
    DB::table('products')->insert(['name' => 'anvil', 'created_at' => now(), 'updated_at' => now()]);

    Http::fake(fn ($request) => str_contains((string) $request->url(), '/aliases/')
        ? Http::response(['collection_name' => 'test_products_x'])
        : Http::response(['name' => 'test_products_x', 'num_documents' => 0]));

    $this->artisan('yolo:search:heal')->assertExitCode(0);

    Bus::assertDispatched(ReimportSearchModel::class);
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

    $this->artisan('yolo:search:heal')->assertExitCode(0);

    Bus::assertNotDispatched(ReimportSearchModel::class);
})->with(['populated', 'empty']);

it('reports and fails when the index cannot even be inspected, without queueing anything', function (): void {
    Bus::fake();

    // A 401 means the cluster no longer honours this app's key — sync:app's
    // problem; queueing rebuilds against it would just fail noisily.
    Http::fake(['*' => Http::response(['message' => 'Forbidden'], 401)]);

    $this->artisan('yolo:search:heal')->assertExitCode(1);

    Bus::assertNotDispatched(ReimportSearchModel::class);
});

it('skips quietly when another heal pass holds the lock', function (): void {
    Bus::fake();
    Http::fake();

    // Combined-services apps run the scheduler on every web task — the lock,
    // not onOneServer(), is what keeps a wipe from triggering N rebuilds.
    Cache::lock('yolo:search:heal', 600)->get();

    $this->artisan('yolo:search:heal')->assertExitCode(0);

    Http::assertNothingSent();
    Bus::assertNotDispatched(ReimportSearchModel::class);
});

it('does nothing on an app without Typesense wiring', function (): void {
    Bus::fake();
    Http::fake();

    config()->set('scout.typesense.client-settings', []);

    $this->artisan('yolo:search:heal')->assertExitCode(0);

    Http::assertNothingSent();
});
