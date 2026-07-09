<?php

declare(strict_types=1);

use Tests\SearchTestbenchCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Codinglabs\Yolo\Runtime\Search\TypesenseClient;

uses(SearchTestbenchCase::class);

it('talks to the app-configured node with the app-configured key', function (): void {
    Http::fake(['*' => Http::response(['name' => 'test_products', 'num_documents' => 3])]);

    (new TypesenseClient())->collection('test_products');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://typesense-0.testing.internal:8108/collections/test_products'
        && $request->header('X-TYPESENSE-API-KEY') === ['scoped-key']);
});

it('reads an absent collection or alias as null', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $client = new TypesenseClient();

    expect($client->collection('test_products'))->toBeNull()
        ->and($client->aliasTarget('test_products'))->toBeNull();
});

it('imports documents as JSONL upserts and fails loudly on any rejected line', function (): void {
    Http::fake(['*/documents/import*' => Http::response("{\"success\":true}\n{\"success\":true}")]);

    (new TypesenseClient())->importDocuments('test_products_x', [
        ['id' => '1', 'name' => 'anvil'],
        ['id' => '2', 'name' => 'rocket skates'],
    ]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/collections/test_products_x/documents/import?action=upsert')
        && $request->body() === "{\"id\":\"1\",\"name\":\"anvil\"}\n{\"id\":\"2\",\"name\":\"rocket skates\"}");
});

it('fails the whole import on one rejected line', function (): void {
    // A partial rebuild swapped live is worse than none — one bad line fails
    // the import before the alias ever moves.
    Http::fake(['*/documents/import*' => Http::response("{\"success\":true}\n{\"success\":false,\"error\":\"field name mismatch\"}")]);

    expect(fn () => (new TypesenseClient())->importDocuments('test_products_x', [['id' => '1'], ['id' => '2']]))
        ->toThrow(RuntimeException::class, 'field name mismatch');
});

it('fails over to the next configured node when one is unreachable', function (): void {
    // A recycling node is routine on Fargate — a connection-level failure
    // must not fail a heal or abort a rebuild against a healthy quorum.
    config()->set('scout.typesense.client-settings.nodes', [
        ['host' => 'typesense-0.testing.internal', 'port' => 8108, 'protocol' => 'http', 'path' => ''],
        ['host' => 'typesense-1.testing.internal', 'port' => 8108, 'protocol' => 'http', 'path' => ''],
    ]);

    Http::fake(function ($request) {
        if (str_contains((string) $request->url(), 'typesense-0')) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response(['name' => 'test_products', 'num_documents' => 3]);
    });

    expect((new TypesenseClient())->collection('test_products'))->toMatchArray(['num_documents' => 3]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'typesense-1.testing.internal'));
});

it('names the fix when the cluster no longer honours the key', function (): void {
    // Minted keys are cluster data — a replaced cluster 401s the stored key,
    // and only sync:app can re-mint it; the message must say so.
    Http::fake(['*' => Http::response(['message' => 'Forbidden'], 401)]);

    expect(fn (): ?array => (new TypesenseClient())->collection('test_products'))
        ->toThrow(RuntimeException::class, 'yolo sync:app');
});
