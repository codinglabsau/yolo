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
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;

/**
 * Mints the app's server-side and search-only Typesense keys, scoped to its own
 * `{prefix}*` collections, into the env-side per-app `.env` — kept out of the
 * developer `.env` (which the admin tier is fenced from) and apart from the
 * env-shared `.env` (which carries the cluster admin key), so a build reads
 * only its own keys, never the admin key or a sibling's.
 *
 * The stored keys are the VALUE truth (sync never rotates them; rotation =
 * delete the lines, run sync:app again) but the cluster is the HONOUR truth:
 * minted keys are raft-replicated cluster data on ephemeral disks, so a full
 * node replacement forgets them while apps keep the baked values — every
 * search 401s behind a green /health. So "already minted" is verified with a
 * scoped probe, and a dead pair is re-created with the SAME values (POST /keys
 * accepts an explicit `value`), so existing builds work again without a rebuild.
 *
 * The idempotency check reads the per-app `.env`, a secret the Observer tier
 * is fenced from — hence {@see SkippedByDeployCheck}: the read tiers skip it
 * rather than 403; `yolo sync` (admin) remains its drift check.
 */
class SyncTypesenseKeyStep implements LongRunning, SkippedByDeployCheck
{
    use RecordsChanges;
    use RecordsWarnings;

    /**
     * ~5 minutes: enough for node boot + target registration + DNS/cert settling
     * on a first sync; instant on a re-sync.
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

        $serverKey = Typesense::appKey();

        if ($serverKey !== null) {
            return $this->reconcileStoredKeys($serverKey, $options);
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

        // On a first sync the env tier provisioned the cluster moments ago, so the
        // public host is usually still settling — wait a bounded spell rather than
        // force a second sync, but fall back to the skip so an unhealthy cluster
        // can't hang the run.
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
     * The probe runs on the plan pass too, so drift is recorded before the
     * dry-run guard and the step survives to apply. An unverifiable probe
     * (connection error, ALB 5xx) reads as honoured with a warning: node health
     * has its own alarms, and a down cluster must not wedge every sync.
     */
    protected function reconcileStoredKeys(string $serverKey, array $options): StepResult
    {
        // A hand-edited file holding only half the pair (mid-rotation), or no
        // public host to probe — nothing verifiable, the stored marker stands.
        $searchKey = Typesense::appSearchKey();

        if ($searchKey === null) {
            return StepResult::SYNCED;
        }

        $searchHost = Typesense::searchHost();

        if ($searchHost === null) {
            return StepResult::SYNCED;
        }

        $honoured = $this->clusterHonours($searchHost, $searchKey);

        if ($honoured === null) {
            $this->recordWarning(sprintf('Could not verify the stored Typesense keys against https://%s — treating them as honoured. Run `yolo sync:app` again if search is failing.', $searchHost));

            return StepResult::SYNCED;
        }

        if ($honoured) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(Typesense::CLIENT_KEY_NAME, 'not honoured by the cluster', 're-created (same value)'));
        $this->recordChange(Change::make(Typesense::SEARCH_KEY_NAME, 'not honoured by the cluster', 're-created (same value)'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        $adminKey = Typesense::adminKey();

        if ($adminKey === null) {
            $this->recordWarning('Typesense keys not re-created — the cluster admin key is missing from the env-shared .env. Run `yolo sync:environment` first.');

            return StepResult::SKIPPED;
        }

        if ($this->mint($searchHost, $adminKey, ['*'], 'server-side', $serverKey) === null
            || $this->mint($searchHost, $adminKey, ['documents:search'], 'browser search-only', $searchKey) === null) {
            $this->recordWarning(sprintf('Typesense keys not re-created — https://%s became unreachable mid-mint. Run `yolo sync:app` again shortly.', $searchHost));

            return StepResult::SKIPPED;
        }

        return StepResult::SYNCED;
    }

    /**
     * 401 is the one answer that means the key is dead; any other status the
     * cluster itself answers (404 collection-not-found is the usual) proves auth
     * passed. A connection error or ALB 5xx says nothing about the key — null,
     * for the caller to fail open on.
     */
    protected function clusterHonours(string $searchHost, string $searchKey): ?bool
    {
        try {
            $response = ($this->http ?? new Client())->get(sprintf('https://%s/collections/%sprobe/documents/search', $searchHost, $this->prefix()), [
                'headers' => ['X-TYPESENSE-API-KEY' => $searchKey],
                'query' => ['q' => '*', 'query_by' => 'id'],
                'timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return null;
        }

        if ($response->getStatusCode() >= 500) {
            return null;
        }

        return $response->getStatusCode() !== 401;
    }

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
     * A 200 means the whole public chain is ready at once — nodes healthy, target
     * registered, DNS resolved, cert valid — exactly what the mint's POST /keys needs.
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
     * An explicit `$value` re-creates a key deterministically so every build
     * that baked it keeps working.
     *
     * @param  array<int, string>  $actions
     */
    protected function mint(string $searchHost, string $adminKey, array $actions, string $role, ?string $value = null): ?string
    {
        try {
            $response = ($this->http ?? new Client())->post(sprintf('https://%s/keys', $searchHost), [
                'headers' => ['X-TYPESENSE-API-KEY' => $adminKey],
                'json' => [
                    'description' => sprintf('%s %s key (YOLO managed)', Manifest::name(), $role),
                    'actions' => $actions,
                    'collections' => [$this->prefix() . '.*'],
                    ...($value !== null ? ['value' => $value] : []),
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
     * Regex-anchored in the key's collections scope so `myapp_products` matches
     * and a sibling's `myapp2_products` never can.
     */
    protected function prefix(): string
    {
        return Helpers::keyedResourceName() . '_';
    }
}
