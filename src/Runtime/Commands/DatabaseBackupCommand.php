<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Commands;

use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Illuminate\Support\Arr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * The in-container backup executor: `mysqldump` each database, compress with
 * zstd, verify the archive, and upload it to the env backups bucket. Nothing to
 * schedule and no runtime config — the crontab YOLO generates for the
 * scheduler host carries the daily entry with every argument baked from the
 * manifest at build time (ProcessCommands::databaseBackup), and
 * `yolo backup:database` runs the same invocation on demand as a one-off
 * task with its output streamed back.
 *
 * Verification happens at the producer, before upload, so a bad dump can
 * never ship — let alone replicate offsite looking healthy:
 *
 *  - `zstd -t` proves the archive is intact (a killed pipe or full disk
 *    leaves a torn file this catches);
 *  - the `Dump completed` trailer proves mysqldump finished — the server
 *    writes it only at the end of a successful dump, so its absence catches
 *    a dropped connection or mid-stream error whatever the exit codes did.
 *    Checking the trailer instead of the pipeline's exit status also
 *    sidesteps `pipefail`, which the images' default shells don't agree on.
 *
 * Uploads land on timestamped keys — `{app}/{database}/{Y-m-d-Hi}.sql.zst` —
 * so every run keeps its own object at any schedule cadence, the history is
 * browsable (ISO timestamps sort lexically), and retention is plain lifecycle
 * expiry on the bucket; versioning stays on purely as tamper armour (a
 * write-only producer cannot destroy an existing object). The upload uses the task role's write-only grant; the archive
 * is deleted locally either way. A failed database is reported and the run moves on to
 * the next — one broken tenant must not cost the rest their backup — but the
 * command exits non-zero so the failure is loud in the scheduler's logs.
 *
 * Run-once is the command's own property: it takes a cache lock, so a
 * combined-services app whose scheduler ticks on every web task still dumps
 * once, and a manual run can't race the scheduled one.
 */
class DatabaseBackupCommand extends Command
{
    /** Bounds a wedged run: a second run may start once this lock expires,
     * roomy enough that a large multi-database dump finishes well inside it. */
    protected const int LOCK_TTL_SECONDS = 6 * 3600;

    protected $signature = 'yolo:backup-database
        {--destination= : The bucket/prefix the dumps upload to (baked into the crontab from the manifest)}
        {--region= : The backups bucket region}
        {--tenants= : Comma-separated tenant database names dumped alongside the default connection}';

    protected $description = 'Dump each database, verify the archive, and upload it to the env backups bucket';

    public function handle(): int
    {
        $destination = (string) $this->option('destination');

        if ($destination === '' || (string) $this->option('region') === '') {
            // The generated crontab and `yolo backup:database` always pass
            // both — a bare manual run is missing its target, not opting out.
            $this->error('Both --destination and --region are required (copy them from docker/crontab).');

            return self::FAILURE;
        }

        $databases = $this->databases();

        // A destination with nothing to dump is a misconfiguration, not a quiet
        // success — succeeding here would read as "backed up" forever.
        if ($databases === []) {
            $this->error('A backup destination is configured but no database is — nothing was backed up.');

            return self::FAILURE;
        }

        $lock = Cache::lock('yolo:backup-database', static::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->info('A backup run already holds the lock — skipping.');

            return self::SUCCESS;
        }

        try {
            $failures = [];

            foreach ($databases as $database) {
                if (! $this->backup($database, $destination)) {
                    $failures[] = $database;
                }
            }
        } finally {
            $lock->release();
        }

        if ($failures !== []) {
            $this->error(sprintf('Backup failed for: %s', implode(', ', $failures)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The default connection's database plus any tenant databases passed in.
     * Tenant ids are the database names (the same contract the per-tenant
     * queue fan-out relies on), so the list needs no tenancy package.
     *
     * @return array<int, string>
     */
    protected function databases(): array
    {
        return collect([$this->connection()['database'] ?? null])
            ->concat(explode(',', (string) $this->option('tenants')))
            ->map(fn ($database): string => trim((string) $database))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function backup(string $database, string $destination): bool
    {
        $started = microtime(true);
        $archive = sys_get_temp_dir() . "/{$database}.sql.zst";

        try {
            if (! $this->dump($database, $archive)) {
                $this->error(sprintf('%s: dump failed.', $database));

                return false;
            }

            if (! $this->verify($archive)) {
                $this->error(sprintf('%s: dump failed verification — not uploading it.', $database));

                return false;
            }

            $this->upload($archive, sprintf('%s/%s/%s.sql.zst', $destination, $database, now()->format('Y-m-d-Hi')));

            $this->info(sprintf(
                '%s: dumped, verified and uploaded %s in %ds.',
                $database,
                $this->humanSize((int) filesize($archive)),
                (int) (microtime(true) - $started),
            ));

            return true;
        } finally {
            @unlink($archive);
        }
    }

    /**
     * Dump straight through zstd so local disk only ever holds the compressed
     * archive — an uncompressed dump can exceed the task's ephemeral storage.
     * Connection details ride environment variables into a fixed shell
     * template, so no value is ever interpolated into the command line
     * (`MYSQL_PWD` also keeps the password out of the process list).
     */
    protected function dump(string $database, string $archive): bool
    {
        $connection = $this->connection();

        $host = Arr::get($connection, 'read.host', $connection['host'] ?? '127.0.0.1');

        $process = new Process(
            command: [
                'sh', '-c',
                'mysqldump --single-transaction --skip-lock-tables --quick'
                . ' --host="$BACKUP_HOST" --port="$BACKUP_PORT" --user="$BACKUP_USER"'
                . ' --databases "$BACKUP_DATABASE" | zstd -T0 -q -f -o "$BACKUP_ARCHIVE"',
            ],
            env: [
                'BACKUP_HOST' => is_array($host) ? (string) Arr::first($host) : (string) $host,
                'BACKUP_PORT' => (string) ($connection['port'] ?? 3306),
                'BACKUP_USER' => (string) ($connection['username'] ?? ''),
                'BACKUP_DATABASE' => $database,
                'BACKUP_ARCHIVE' => $archive,
                'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
            ],
            timeout: null,
        );

        return $this->runProcess($process) === 0;
    }

    /**
     * Integrity then completeness — see the class docblock for why the
     * trailer stands in for the dump's exit status.
     */
    protected function verify(string $archive): bool
    {
        if ($this->runProcess(new Process(['zstd', '-t', '-q', $archive], timeout: null)) !== 0) {
            return false;
        }

        return str_contains($this->archiveTail($archive), 'Dump completed');
    }

    /**
     * The last bytes of the decompressed dump — streamed, so a large archive
     * never lands on disk or in memory during verification.
     */
    protected function archiveTail(string $archive): string
    {
        $tail = new Process(
            command: ['sh', '-c', 'zstd -dc "$BACKUP_ARCHIVE" | tail -c 512'],
            env: ['BACKUP_ARCHIVE' => $archive],
            timeout: null,
        );

        return $this->runProcess($tail) === 0 ? $tail->getOutput() : '';
    }

    protected function upload(string $archive, string $path): void
    {
        [$bucket, $key] = explode('/', $path, 2);

        // ObjectUploader switches to multipart above its threshold, so a dump
        // larger than a single PUT allows still lands (the task role grants
        // PutObject + AbortMultipartUpload on this prefix).
        (new ObjectUploader($this->s3(), $bucket, $key, fopen($archive, 'r')))->upload();
    }

    /**
     * @return array<string, mixed>
     */
    protected function connection(): array
    {
        return (array) config('database.connections.' . config('database.default'));
    }

    protected function s3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => (string) $this->option('region'),
        ]);
    }

    protected function runProcess(Process $process): int
    {
        return $process->run();
    }

    protected function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => sprintf('%.1fGB', $bytes / 1_073_741_824),
            $bytes >= 1_048_576 => sprintf('%.1fMB', $bytes / 1_048_576),
            default => sprintf('%.0fKB', max($bytes, 1024) / 1024),
        };
    }
}
