<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Runtime\Commands\MysqlBackupCommand;

uses(TestbenchCase::class);

/**
 * A MysqlBackupCommand whose shell and S3 boundaries are recorded instead of
 * executed: dump processes "succeed" by touching the archive, verification
 * reads a canned trailer, uploads record their destination. Everything above
 * those boundaries — gating, locking, database assembly, verification logic,
 * failure aggregation — runs for real.
 */
function fakeBackupCommand(Application $app, string $trailer = '-- Dump completed on 2026-01-01', array $failFor = []): MysqlBackupCommand
{
    $command = new class($trailer, $failFor) extends MysqlBackupCommand
    {
        /** @var array<int, array{commandLine: string, env: array<string, string>}> */
        public array $processes = [];

        /** @var array<int, string> */
        public array $uploads = [];

        public function __construct(protected string $trailer, protected array $failFor)
        {
            parent::__construct();
        }

        protected function runProcess(Process $process): int
        {
            $env = $process->getEnv();

            $this->processes[] = ['commandLine' => $process->getCommandLine(), 'env' => $env];

            if (in_array($env['BACKUP_DATABASE'] ?? null, $this->failFor, true)) {
                return 1;
            }

            if (isset($env['BACKUP_ARCHIVE']) && isset($env['BACKUP_DATABASE'])) {
                touch($env['BACKUP_ARCHIVE']);
            }

            return 0;
        }

        protected function archiveTail(string $archive): string
        {
            return $this->trailer;
        }

        protected function upload(string $archive, string $path): void
        {
            $this->uploads[] = $path;
        }
    };

    $command->setLaravel($app);

    return $command;
}

function runBackupCommand(MysqlBackupCommand $command): int
{
    return $command->run(new ArrayInput([]), new BufferedOutput());
}

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => 'db.internal',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app-user',
        'password' => 'secret',
    ]);
    config()->set('yolo.backup.destination', 'yolo-111111111111-testing-dumps/my-app');
    config()->set('yolo.backup.tenants');
});

it('does nothing without a configured destination', function (): void {
    config()->set('yolo.backup.destination');

    $command = fakeBackupCommand($this->app);

    expect(runBackupCommand($command))->toBe(0)
        ->and($command->uploads)->toBe([]);
});

it('dumps, verifies and uploads the default database to its app prefix', function (): void {
    $command = fakeBackupCommand($this->app);

    expect(runBackupCommand($command))->toBe(0)
        ->and($command->uploads)->toBe(['yolo-111111111111-testing-dumps/my-app/app.sql.zst']);
});

it('dumps every tenant database alongside the default, deduped', function (): void {
    config()->set('yolo.backup.tenants', 'acme,globex,app');

    $command = fakeBackupCommand($this->app);

    expect(runBackupCommand($command))->toBe(0)
        ->and($command->uploads)->toBe([
            'yolo-111111111111-testing-dumps/my-app/app.sql.zst',
            'yolo-111111111111-testing-dumps/my-app/acme.sql.zst',
            'yolo-111111111111-testing-dumps/my-app/globex.sql.zst',
        ]);
});

it('passes connection details as environment, never on the command line', function (): void {
    $command = fakeBackupCommand($this->app);
    runBackupCommand($command);

    $dump = collect($command->processes)->first(fn (array $process): bool => str_contains((string) $process['commandLine'], 'mysqldump'));

    // The template pipes straight through zstd and references only env vars —
    // MYSQL_PWD keeps the password off the process list entirely.
    expect($dump['commandLine'])->toContain('--single-transaction')
        ->toContain('--databases "$BACKUP_DATABASE"')
        ->toContain('zstd -T0')
        ->not->toContain('secret')
        ->and($dump['env']['MYSQL_PWD'])->toBe('secret')
        ->and($dump['env']['BACKUP_HOST'])->toBe('db.internal')
        ->and($dump['env']['BACKUP_USER'])->toBe('app-user');
});

it('refuses to upload a dump whose trailer is missing, fails the run, and keeps going', function (): void {
    config()->set('yolo.backup.tenants', 'acme');

    // Every archive verifies structurally but the dump never completed — the
    // trailer is the completeness proof, so nothing may ship.
    $command = fakeBackupCommand($this->app, trailer: 'INSERT INTO `orders` VALUES (…');

    expect(runBackupCommand($command))->toBe(1)
        ->and($command->uploads)->toBe([]);
});

it('reports the failed database but still backs up the rest', function (): void {
    config()->set('yolo.backup.tenants', 'acme');

    $command = fakeBackupCommand($this->app, failFor: ['app']);

    // One broken database must not cost the others their backup — but the run
    // exits non-zero so the failure is loud in the scheduler's logs.
    expect(runBackupCommand($command))->toBe(1)
        ->and($command->uploads)->toBe(['yolo-111111111111-testing-dumps/my-app/acme.sql.zst']);
});

it('skips when another run holds the lock', function (): void {
    $held = Cache::lock('yolo:backup-databases', 60);
    $held->get();

    try {
        $command = fakeBackupCommand($this->app);

        expect(runBackupCommand($command))->toBe(0)
            ->and($command->uploads)->toBe([]);
    } finally {
        $held->release();
    }
});

it('fails loudly when a destination is configured but no database is', function (): void {
    // Succeeding with nothing to dump would read as "backed up" forever — the
    // one silent state this command must never report.
    config()->set('database.connections.mysql.database');

    $command = fakeBackupCommand($this->app);

    expect(runBackupCommand($command))->toBe(1)
        ->and($command->uploads)->toBe([]);
});
