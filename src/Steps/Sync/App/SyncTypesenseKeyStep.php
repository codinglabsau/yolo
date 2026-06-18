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
class SyncTypesenseKeyStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __construct(protected string $environment, protected ?Client $http = null) {}

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

        $serverKey = $this->mint($searchHost, $adminKey, ['*'], 'server-side');
        $searchKey = $this->mint($searchHost, $adminKey, ['documents:search'], 'browser search-only');

        if ($serverKey === null || $searchKey === null) {
            $this->recordWarning(sprintf('Typesense key not minted — https://%s is not reachable yet (DNS/health may still be settling). Run `yolo sync:app` again shortly.', $searchHost));

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
