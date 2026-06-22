<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use GuzzleHttp\Client;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\WaitReporter;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use GuzzleHttp\Exception\GuzzleException;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;

/**
 * Mints this app its two Typesense keys, both scoped to its own `{prefix}*`
 * collections (the Algolia secured-key model: a leaked key reaches that app's
 * collections only) and written into the app's environment-side per-app `.env`
 * (env/.env.{app} in the env config bucket), where the next build merges them
 * in like any other env value:
 *
 * - a server-side key (all actions) the app indexes and queries with from PHP;
 * - a search-only key (documents:search) safe to embed in the page, which the
 *   browser carries when it queries the public search host directly.
 *
 * Kept out of the app's developer `.env` (which the admin tier running sync is
 * fenced from) and apart from the env-shared `.env` (which carries the cluster
 * admin key), so each app's build reads only its own minted keys — never the
 * admin key, never a sibling's. Minted exactly once, the pair together — the
 * keys already in the env-side file are the source of truth, so sync never
 * re-mints or rotates (rotation = delete the lines, run sync:app again).
 *
 * The mint talks to the cluster's data plane over the public search host with
 * the admin key — the one place YOLO does — so while the cluster or its
 * ingress isn't up yet (first sync ordering: claim published → env tier
 * provisions → this step), it skips with instructions rather than failing the
 * sync.
 */
class SyncTypesenseKeyStep implements LongRunning
{
    use RecordsChanges;
    use RecordsWarnings;

    /**
     * Bounded wait for the public search host to answer /health before minting —
     * 5s polls up to ~5 minutes. Enough for node boot + target registration +
     * DNS/cert settling on a first sync; instant on a re-sync where it's already up.
     */
    protected const int HEALTH_POLL_INTERVAL_SECONDS = 5;

    protected const int HEALTH_POLL_ATTEMPTS = 60;

    public function __construct(protected string $environment, protected ?Client $http = null) {}

    public function patienceMessage(): string
    {
        return 'Waiting for the Typesense search endpoint to answer /health before minting this app\'s search keys — usually under a minute';
    }

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return StepResult::SKIPPED;
        }

        if (Typesense::appKey() !== null) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(Typesense::CLIENT_KEY_NAME, 'absent', 'minted (scoped to ' . $this->prefix() . '*)'));
        $this->recordChange(Change::make(Typesense::SEARCH_KEY_NAME, 'absent', 'minted (search-only, scoped to ' . $this->prefix() . '*)'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $adminKey = Typesense::adminKey();
        $searchHost = Typesense::searchHost();

        if ($adminKey === null || $searchHost === null) {
            $this->recordWarning('Typesense key not minted — the cluster is not provisioned yet. Run `yolo sync:app` again once `yolo sync:environment` has it up.');

            return StepResult::SKIPPED;
        }

        // The env tier provisions the cluster moments before this step on a first
        // sync, so the public search host is usually still settling — node boot,
        // target registration, DNS/cert propagation. Wait a bounded spell for
        // /health to answer rather than skipping and forcing a second sync; if it
        // never comes up, fall back to the skip so an unhealthy cluster can't hang
        // the run.
        if (! $this->awaitHealthy($searchHost)) {
            $this->recordWarning(sprintf('Typesense key not minted — https://%s did not answer /health within %ds (DNS/cert/health still settling, or the cluster is unhealthy). Run `yolo sync:app` again once it is up.', $searchHost, self::HEALTH_POLL_ATTEMPTS * self::HEALTH_POLL_INTERVAL_SECONDS));

            return StepResult::SKIPPED;
        }

        $serverKey = $this->mint($searchHost, $adminKey, ['*'], 'server-side');
        $searchKey = $this->mint($searchHost, $adminKey, ['documents:search'], 'browser search-only');

        if ($serverKey === null || $searchKey === null) {
            $this->recordWarning(sprintf('Typesense key not minted — https://%s became unreachable mid-mint. Run `yolo sync:app` again shortly.', $searchHost));

            return StepResult::SKIPPED;
        }

        Aws::s3()->putObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => Paths::s3EnvAppEnvKey(),
            'Body' => $this->bodyWithKeys([
                Typesense::CLIENT_KEY_NAME => $serverKey,
                Typesense::SEARCH_KEY_NAME => $searchKey,
            ]),
        ]);

        return StepResult::CREATED;
    }

    /**
     * Poll the public search host's /health until it answers or the bounded
     * attempts are spent — wraps the pure {@see pollHealthy} loop with the real
     * probe, the heartbeat, and the sleep.
     */
    protected function awaitHealthy(string $searchHost): bool
    {
        return static::pollHealthy(
            fn (): bool => $this->isHealthy($searchHost),
            self::HEALTH_POLL_ATTEMPTS,
            function (): void {
                WaitReporter::poll();
                sleep(self::HEALTH_POLL_INTERVAL_SECONDS);
            },
        );
    }

    /**
     * Poll $isHealthy up to $attempts times, running $betweenAttempts (the
     * heartbeat + sleep in production) after each failed attempt except the last.
     * Returns true on the first healthy check, false once the attempts are spent —
     * it never blocks beyond the bound, so a cluster that never comes up falls
     * through to the caller's skip rather than hanging the sync.
     *
     * @param  callable(): bool  $isHealthy
     * @param  callable(): void  $betweenAttempts
     */
    public static function pollHealthy(callable $isHealthy, int $attempts, callable $betweenAttempts): bool
    {
        $attempts = max(1, $attempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($isHealthy()) {
                return true;
            }

            if ($attempt < $attempts) {
                $betweenAttempts();
            }
        }

        return false;
    }

    /**
     * GET the search host's unauthenticated /health. A 200 means the whole public
     * chain is ready at once — nodes healthy, target registered, DNS resolved,
     * cert valid — exactly what the mint's POST /keys then needs. A non-200 or a
     * connection error (DNS/cert not ready) reads as not-yet-healthy.
     */
    protected function isHealthy(string $searchHost): bool
    {
        try {
            $response = ($this->http ?? new Client())->get(sprintf('https://%s/health', $searchHost), [
                'timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return false;
        }

        return $response->getStatusCode() === 200;
    }

    /**
     * POST /keys with the given actions on this app's own collection prefix
     * only. `role` names the key in its Typesense description so the two are
     * told apart on the cluster.
     *
     * @param  array<int, string>  $actions
     */
    protected function mint(string $searchHost, string $adminKey, array $actions, string $role): ?string
    {
        try {
            $response = ($this->http ?? new Client())->post(sprintf('https://%s/keys', $searchHost), [
                'headers' => ['X-TYPESENSE-API-KEY' => $adminKey],
                'json' => [
                    'description' => sprintf('%s %s key (YOLO managed)', Manifest::name(), $role),
                    'actions' => $actions,
                    'collections' => [$this->prefix() . '.*'],
                ],
                'timeout' => 15,
            ]);
        } catch (GuzzleException) {
            return null;
        }

        $key = json_decode((string) $response->getBody(), true)['value'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The current env-side per-app `.env` with the minted keys appended — absent
     * file reads as empty, so the first mint creates it. Existing content is
     * preserved byte-for-byte.
     *
     * @param  array<string, string>  $values
     */
    protected function bodyWithKeys(array $values): string
    {
        $current = $this->currentBody();

        if ($current !== '' && ! str_ends_with($current, "\n")) {
            $current .= "\n";
        }

        foreach ($values as $name => $value) {
            $current .= sprintf("%s=%s\n", $name, $value);
        }

        return $current;
    }

    protected function currentBody(): string
    {
        try {
            return (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3EnvAppEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return '';
            }

            throw $e;
        }
    }

    /**
     * The app's collection prefix — regex-anchored in the key's collections
     * scope so `myapp_products` matches and a sibling's `myapp2_products`
     * never can.
     */
    protected function prefix(): string
    {
        return Helpers::keyedResourceName() . '_';
    }
}
