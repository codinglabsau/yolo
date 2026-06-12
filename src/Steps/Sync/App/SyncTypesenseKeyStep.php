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
 * Mints this app a Typesense API key scoped to its own `{prefix}*` collections
 * (the Algolia secured-key model: a leaked app key reaches that app's
 * collections only) and writes it into the app's `.env.{environment}` in its
 * config bucket, where the next build picks it up like any other env value.
 * Minted exactly once — the key already in the env file is the source of
 * truth, so sync never re-mints or rotates (rotation = delete the line, run
 * sync:app again).
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

    public function __construct(protected ?Client $http = null) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return StepResult::SKIPPED;
        }

        if ($this->appEnv() !== null && str_contains($this->appEnv(), Typesense::ADMIN_KEY_NAME . '=')) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(Typesense::ADMIN_KEY_NAME, 'absent', 'minted (scoped to ' . $this->prefix() . '*)'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $adminKey = Typesense::adminKey();
        $searchHost = Typesense::searchHost();

        if ($adminKey === null || $searchHost === null) {
            warning('Typesense key not minted — the cluster is not provisioned yet. Run `yolo sync:app` again once `yolo sync:environment` has it up.');

            return StepResult::SKIPPED;
        }

        $key = $this->mint($searchHost, $adminKey);

        if ($key === null) {
            warning(sprintf('Typesense key not minted — https://%s is not reachable yet (DNS/health may still be settling). Run `yolo sync:app` again shortly.', $searchHost));

            return StepResult::SKIPPED;
        }

        $this->appendToAppEnv($key);

        return StepResult::CREATED;
    }

    /**
     * POST /keys with actions on this app's own collection prefix only.
     */
    protected function mint(string $searchHost, string $adminKey): ?string
    {
        try {
            $response = ($this->http ?? new Client())->post(sprintf('https://%s/keys', $searchHost), [
                'headers' => ['X-TYPESENSE-API-KEY' => $adminKey],
                'json' => [
                    'description' => sprintf('%s server-side key (YOLO managed)', Manifest::name()),
                    'actions' => ['*'],
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

    protected function appendToAppEnv(string $key): void
    {
        $current = (string) $this->appEnv();

        if ($current !== '' && ! str_ends_with($current, "\n")) {
            $current .= "\n";
        }

        Aws::s3()->putObject([
            'Bucket' => Paths::s3ConfigBucket(),
            'Key' => $this->envKey(),
            'Body' => $current . sprintf("%s=%s\n", Typesense::ADMIN_KEY_NAME, $key),
        ]);
    }

    protected function appEnv(): ?string
    {
        try {
            return (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3ConfigBucket(),
                'Key' => $this->envKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }

    protected function envKey(): string
    {
        return sprintf('.env.%s', Helpers::environment());
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
