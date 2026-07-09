<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Tests\Fixtures\Search\Widget;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Search\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(SearchTestbenchCase::class);

beforeEach(function (): void {
    Schema::create('products', function ($table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    DB::table('products')->insert([
        ['name' => 'anvil', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
        ['name' => 'rocket skates', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
    ]);
});

function fakeReimportCluster(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/documents/import') => Http::response("{\"success\":true}\n{\"success\":true}"),
            str_ends_with($url, '/collections') && $request->method() === 'POST' => Http::response(['name' => 'created']),
            str_contains($url, '/aliases/') && $request->method() === 'PUT' => Http::response(['name' => 'aliased']),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    });
}

it('rebuilds every searchable model under --all', function (): void {
    fakeReimportCluster();

    $this->artisan('scout:reimport', ['--all' => true])->assertExitCode(0);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains((string) $request->url(), '/aliases/test_products'));
});

it('rebuilds an explicitly named model', function (): void {
    fakeReimportCluster();

    $this->artisan('scout:reimport', ['model' => [Product::class]])->assertExitCode(0);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/documents/import'));
});

it('refuses a class that is not a searchable model', function (): void {
    Http::fake();

    $this->artisan('scout:reimport', ['model' => [Widget::class]])
        ->expectsOutputToContain('not a searchable model')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('requires an explicit choice when run non-interactively with no models', function (): void {
    Http::fake();

    // A fat-fingered scheduler entry must never rebuild the world by
    // accident — headless runs name their models or say --all.
    $this->artisan('scout:reimport', ['--no-interaction' => true])
        ->expectsOutputToContain('pass --all')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
