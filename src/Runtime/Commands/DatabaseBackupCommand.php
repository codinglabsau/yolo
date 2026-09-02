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
 * Every argument is baked into the generated crontab from the manifest at build
 * time (ProcessCommands::databaseBackup); `yolo backup:database` runs the same
 * invocation on demand.
 *
 * Verification happens at the producer, before upload, so a bad dump can never
 * ship looking healthy: `zstd -t` catches a torn archive (killed pipe, full
 * disk), and the `Dump completed` trailer proves mysqldump finished — the server
 * writes it only at the end of a successful dump, and checking it instead of the
 * pipeline's exit status sidesteps `pipefail`, which the images' shells don't
 * agree on.
 *
 * Timestamped keys mean every run keeps its own object at any cadence; retention
 * is lifecycle expiry on the bucket, and versioning stays on purely as tamper
 * armour (a write-only producer cannot destroy an existing object). A failed
 * database is reported and the run moves on — one broken tenant must not cost
 * the rest their backup — but the command exits non-zero.
 *
 * The cache lock is the command's own: a combined-services app's scheduler ticks
 * on every web task, and a manual run must not race the scheduled one.
 */
class DatabaseBackupCommand extends Command
{
    /** Bounds a wedged run; roomy enough for a large multi-database dump. */
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
            // a bare manual run is missing its target, not opting out
            $this->error('Both --destination and --region are required (copy them from docker/crontab).');

            return self::FAILURE;
        }

        $databases = $this->databases();

        // succeeding here would read as "backed up" forever
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
     * Tenant ids are the database names (the same contract the per-tenant queue
     * fan-out relies on), so no tenancy package is needed.
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
     * Piped straight through zstd — an uncompressed dump can exceed the task's
     * ephemeral storage. Connection details ride env vars into a fixed shell
     * template so nothing is interpolated into the command line (`MYSQL_PWD`
     * also keeps the password out of the process list).
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

    protected function verify(string $archive): bool
    {
        if ($this->runProcess(new Process(['zstd', '-t', '-q', $archive], timeout: null)) !== 0) {
            return false;
        }

        return str_contains($this->archiveTail($archive), 'Dump completed');
    }

    /**
     * Streamed, so a large archive never lands on disk or in memory.
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

        // ObjectUploader goes multipart above its threshold — the task role grants
        // PutObject + AbortMultipartUpload on this prefix for that.
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
