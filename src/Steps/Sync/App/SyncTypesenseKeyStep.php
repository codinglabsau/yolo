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
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use GuzzleHttp\Exception\GuzzleException;
use Codinglabs\Yolo\Concerns\RecordsChanges;

use function Laravel\Prompts\warning;

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
 * admin key, never a sibling's. Each key is minted exactly once — the keys
 * already in the env-side file are the source of truth, so sync mints only the
 * ones still missing and never rotates (rotation = delete the line, run
 * sync:app again); an app provisioned before the search key existed gets it
 * backfilled on the next sync.
 *
 * The mint talks to the cluster's data plane over the public search host with
 * the admin key — the one place YOLO does — so while the cluster or its
 * ingress isn't up yet (first sync ordering: claim published → env tier
 * provisions → this step), it skips with instructions rather than failing the
 * sync.
 */
class SyncTypesenseKeyStep implements Step
{
    use RecordsChanges;

    public function __construct(protected string $environment, protected ?Client $http = null) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return StepResult::SKIPPED;
        }

        $needServerKey = Typesense::appKey() === null;
        $needSearchKey = Typesense::searchKey() === null;

        if (! $needServerKey && ! $needSearchKey) {
            return StepResult::SYNCED;
        }

        if ($needServerKey) {
            $this->recordChange(Change::make(Typesense::CLIENT_KEY_NAME, 'absent', 'minted (scoped to ' . $this->prefix() . '*)'));
        }

        if ($needSearchKey) {
            $this->recordChange(Change::make(Typesense::SEARCH_KEY_NAME, 'absent', 'minted (search-only, scoped to ' . $this->prefix() . '*)'));
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $adminKey = Typesense::adminKey();
        $searchHost = Typesense::searchHost();

        if ($adminKey === null || $searchHost === null) {
            warning('Typesense key not minted — the cluster is not provisioned yet. Run `yolo sync:app` again once `yolo sync:environment` has it up.');

            return StepResult::SKIPPED;
        }

        $minted = [];

        if ($needServerKey) {
            $serverKey = $this->mint($searchHost, $adminKey, ['*'], 'server-side');

            if ($serverKey === null) {
                return $this->skipUnreachable($searchHost);
            }

            $minted[Typesense::CLIENT_KEY_NAME] = $serverKey;
        }

        if ($needSearchKey) {
            $searchKey = $this->mint($searchHost, $adminKey, ['documents:search'], 'browser search-only');

            if ($searchKey === null) {
                return $this->skipUnreachable($searchHost);
            }

            $minted[Typesense::SEARCH_KEY_NAME] = $searchKey;
        }

        Aws::s3()->putObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => Paths::s3EnvAppEnvKey(),
            'Body' => $this->bodyWithKeys($minted),
        ]);

        return StepResult::CREATED;
    }

    /**
     * The cluster answered the admin key but a /keys POST didn't land — DNS or
     * node health is still settling. Skip with instructions; the next sync
     * mints whatever is still missing.
     */
    protected function skipUnreachable(string $searchHost): StepResult
    {
        warning(sprintf('Typesense key not minted — https://%s is not reachable yet (DNS/health may still be settling). Run `yolo sync:app` again shortly.', $searchHost));

        return StepResult::SKIPPED;
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
     * preserved byte-for-byte, so a backfilled search key lands beside the
     * server key already there.
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
