<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Aws\S3\Exception\S3Exception;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;

/**
 * Shared plumbing for the environment:* file commands — moving the
 * environment's own artefacts (the env manifest, the env-shared .env) between
 * the env config bucket and gitignored local working copies, with a key-level
 * diff and confirmation before every upload.
 */
trait ManagesEnvironmentFiles
{
    /**
     * The local working copy of the env-shared .env — env-suffixed (so copies
     * for different environments coexist) and gitignored.
     */
    protected function sharedEnvLocalPath(): string
    {
        return Paths::base(sprintf('.env.%s.shared', Helpers::environment()));
    }

    /**
     * Download one object from the env config bucket, cleaning up the partial
     * file the SDK's SaveAs sink leaves behind when the object is missing.
     */
    protected function download(string $key, string $saveAs): bool
    {
        try {
            Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => $key,
                'SaveAs' => $saveAs,
            ]);

            return true;
        } catch (S3Exception) {
            if (file_exists($saveAs) && filesize($saveAs) === 0) {
                unlink($saveAs);
            }

            return false;
        }
    }

    protected function remote(string $key): string
    {
        return (string) Aws::s3()->getObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ])['Body'];
    }

    protected function upload(string $key, string $body, string $label): void
    {
        note(sprintf('Uploading %s...', $label));

        Aws::s3()->putObject([
            'Body' => $body,
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ]);

        info('Uploaded successfully.');
    }

    /**
     * Offer to delete the local working copy after a successful push —
     * defaulting to yes. The bucket is the source of truth the moment the
     * upload lands; a copy left on disk only invites staleness, and for env
     * files it's secrets sitting around for anything on the machine to read.
     */
    protected function confirmDeleteLocal(string $path, string $label): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (confirm(sprintf('Delete the local %s? The bucket holds the truth now.', $label), default: true)) {
            unlink($path);

            info(sprintf('Deleted local %s.', $label));
        }
    }

    /**
     * Show a key-level current → new diff and ask before uploading.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     */
    protected function confirmDifferences(array $current, array $new, string $label): bool
    {
        $differences = collect($current)
            ->diffAssoc($new)
            ->union(collect($new)->diffAssoc($current))
            ->keys();

        if ($differences->isNotEmpty()) {
            table(
                ['Key', 'Current Value', 'New Value'],
                $differences->map(fn ($key): array => [
                    $key,
                    $current[$key] ?? null,
                    $new[$key] ?? null,
                ])->toArray()
            );
        }

        $confirmed = $differences->isEmpty()
            ? confirm(sprintf('No changes detected in %s - do you want to upload anyway?', $label))
            : confirm(sprintf('Are you sure you want to upload these changes to %s?', $label));

        if (! $confirmed) {
            info('🐥 Nothing uploaded.');
        }

        return $confirmed;
    }

    /**
     * Flatten a parsed manifest to dot-keyed scalar strings for diffing —
     * json_encode keeps array leaves (e.g. an empty services map) comparable
     * and printable.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    protected function dot(array $manifest): array
    {
        return collect(Arr::dot($manifest))
            ->map(fn ($value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value))
            ->all();
    }
}
