<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Closure;
use RuntimeException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;

/**
 * Built on the app's own Scout wiring (`scout.typesense.client-settings`), so
 * everything this client can touch is already inside the app's `{prefix}*`
 * scope. Deliberately not typesense-php: the app may not ship it (Scout only
 * suggests it), and Laravel's Http client keeps every call fakeable in tests.
 */
class TypesenseClient
{
    public function collection(string $name): ?array
    {
        $response = $this->attempt(fn (PendingRequest $request) => $request->get("/collections/{$name}"));

        if ($response->status() === 404) {
            return null;
        }

        return $this->guard($response)->json();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function createCollection(array $schema): void
    {
        $this->guard($this->attempt(fn (PendingRequest $request) => $request->post('/collections', $schema)));
    }

    public function deleteCollection(string $name): void
    {
        $response = $this->attempt(fn (PendingRequest $request) => $request->delete("/collections/{$name}"));

        if ($response->status() !== 404) {
            $this->guard($response);
        }
    }

    public function aliasTarget(string $name): ?string
    {
        $response = $this->attempt(fn (PendingRequest $request) => $request->get("/aliases/{$name}"));

        if ($response->status() === 404) {
            return null;
        }

        return $this->guard($response)->json('collection_name');
    }

    /**
     * Atomic on the cluster, so readers never see a partial index.
     */
    public function upsertAlias(string $name, string $collection): void
    {
        $this->guard($this->attempt(fn (PendingRequest $request) => $request->put("/aliases/{$name}", ['collection_name' => $collection])));
    }

    /**
     * Any failed line fails the import loudly — a silently partial rebuild
     * swapped live is worse than none.
     *
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function importDocuments(string $collection, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $body = implode("\n", array_map(fn (array $document): string => (string) json_encode($document), $documents));

        // a chunk of large documents on a busy node can legitimately outlast 30s
        $response = $this->guard($this->attempt(fn (PendingRequest $request) => $request
            ->timeout(120)
            ->withBody($body, 'text/plain')
            ->post("/collections/{$collection}/documents/import?action=upsert")));

        foreach (explode("\n", trim($response->body())) as $line) {
            $result = json_decode($line, true);

            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException(sprintf(
                    'Typesense rejected a document during the %s import: %s',
                    $collection,
                    $result['error'] ?? $line,
                ));
            }
        }
    }

    /**
     * Node failover: a recycling Fargate node is routine, so a connection-level
     * failure moves to the next node rather than aborting against a healthy
     * quorum. HTTP-level answers (including errors) return immediately.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    protected function attempt(Closure $call): Response
    {
        $nodes = $this->nodes();

        foreach ($nodes as $index => $node) {
            try {
                return $call($this->request($node));
            } catch (ConnectionException $e) {
                if ($index === count($nodes) - 1) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('scout.typesense.client-settings declares no nodes — is this app configured for the Typesense Scout driver?');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(): array
    {
        $settings = (array) config('scout.typesense.client-settings');

        $nodes = array_values(array_filter([
            $settings['nearest_node'] ?? null,
            ...(array) ($settings['nodes'] ?? []),
        ], fn ($node): bool => is_array($node) && isset($node['host'])));

        if ($nodes === []) {
            throw new RuntimeException('scout.typesense.client-settings declares no nodes — is this app configured for the Typesense Scout driver?');
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function request(array $node): PendingRequest
    {
        $settings = (array) config('scout.typesense.client-settings');

        return Http::baseUrl(sprintf(
            '%s://%s:%s%s',
            $node['protocol'] ?? 'http',
            $node['host'],
            $node['port'] ?? 8108,
            $node['path'] ?? '',
        ))
            ->withHeaders(['X-TYPESENSE-API-KEY' => (string) ($settings['api_key'] ?? '')])
            ->timeout(30)
            ->connectTimeout(5);
    }

    /**
     * 401 gets its own message: the stored key is cluster data, so a replaced
     * cluster stops honouring it — recoverable only by `yolo sync:app`.
     */
    protected function guard(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new RuntimeException('Typesense no longer honours this app\'s API key — the cluster was likely replaced. Run `yolo sync:app <environment>` to re-mint the stored keys, then try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Typesense request failed (%d): %s', $response->status(), $response->body()));
        }

        return $response;
    }
}
