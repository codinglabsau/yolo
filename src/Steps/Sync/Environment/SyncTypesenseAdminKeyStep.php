<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\RecordsChanges;

/**
 * Seed-generates the cluster's admin API key into the env-shared .env in the
 * env config bucket. Generated exactly once and never rotated by sync — a
 * rotation is an operator act (edit the value via environment:env:pull/push;
 * the new fingerprint re-tags the image and rolls the nodes). The key never
 * leaves the env tier: it's baked into the env-scoped image, and app builds
 * never read this bucket. Teardown leaves the key in place — it's a line in
 * the operator's secrets channel, not infrastructure, and a re-offer reuses
 * it.
 */
class SyncTypesenseAdminKeyStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        if (Typesense::adminKey() !== null) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(Typesense::ADMIN_KEY_NAME, 'absent', 'generated'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        Aws::s3()->putObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => Paths::s3SharedEnvKey(),
            'Body' => $this->bodyWithKey(),
        ]);

        // Later steps in this same pass (the image build, the task definition)
        // read the key through the memoised accessor — reset so they see it.
        Typesense::reset();

        return StepResult::CREATED;
    }

    /**
     * The current env-shared .env with the generated key appended — absent
     * file reads as empty, so the first service to need the channel creates
     * it. Existing content is preserved byte-for-byte.
     */
    protected function bodyWithKey(): string
    {
        $current = $this->currentBody();

        if ($current !== '' && ! str_ends_with($current, "\n")) {
            $current .= "\n";
        }

        return $current . sprintf("%s=%s\n", Typesense::ADMIN_KEY_NAME, bin2hex(random_bytes(24)));
    }

    protected function currentBody(): string
    {
        try {
            return (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3SharedEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return '';
            }

            throw $e;
        }
    }
}
