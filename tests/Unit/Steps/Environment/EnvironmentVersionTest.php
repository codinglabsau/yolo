<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncEnvironmentVersionStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/**
 * The step with the CLI version pinned — the real value in a test run is
 * whatever pin this checkout happens to be on.
 */
function versionStepRunningAs(string $version): SyncEnvironmentVersionStep
{
    return new class($version) extends SyncEnvironmentVersionStep
    {
        public function __construct(protected string $pinnedVersion) {}

        protected function cliVersion(): string
        {
            return $this->pinnedVersion;
        }
    };
}

it('stamps an unstamped environment with the running release', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('missing', new Command('GetObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)]),
    ], $captured);

    // Plan: pending, no write — and the absent marker (a greenfield bucket
    // reads the same) never throws.
    $planned = versionStepRunningAs('1.2.0');
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($planned->changes())->toHaveCount(1);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');

    EnvironmentVersion::reset();

    expect((versionStepRunningAs('1.2.0'))([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutObject');
    expect($put['args']['Key'])->toBe('yolo-version')
        ->and((string) $put['args']['Body'])->toBe("1.2.0\n");
});

it('advances an older stamp and never regresses a newer one', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "1.2.0\n"]),
    ], $captured);

    // A newer release advances the record.
    $advancing = versionStepRunningAs('1.3.0');
    expect($advancing(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($advancing->changes())->toHaveCount(1);

    EnvironmentVersion::reset();

    // An older release re-syncing is legitimate — it just doesn't get to
    // lower the record its successor set.
    $regressing = versionStepRunningAs('1.1.0');
    expect($regressing(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect($regressing->changes())->toBeEmpty();

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('never advances the record from a dev pin', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "1.2.0\n"]),
    ], $captured);

    expect((versionStepRunningAs('dev-main'))([]))->toBe(StepResult::SKIPPED);
    expect(array_column($captured, 'name'))->toBeEmpty(); // not even a read
});

it('warns loudly when the running CLI is older than the environment', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "1.3.0\n"]),
    ], $captured);

    $warnings = EnvironmentVersion::skewWarnings('1.2.0');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('OLDER')
        ->and($warnings[0])->toContain('1.2.0')
        ->and($warnings[0])->toContain('1.3.0');
});

it('stays silent when the CLI matches or outruns the stamp, or when either side is unordered', function (string $cli, ?string $stamped): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => $stamped === null
            ? new S3Exception('missing', new Command('GetObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)])
            : new Result(['Body' => $stamped . "\n"]),
    ], $captured);

    expect(EnvironmentVersion::skewWarnings($cli))->toBeEmpty();
})->with([
    'in step' => ['1.3.0', '1.3.0'],
    'ahead of the stamp' => ['1.4.0', '1.3.0'],
    'dev pin (unordered)' => ['dev-main', '1.3.0'],
    'unstamped environment' => ['1.3.0', null],
]);

it('treats an unreadable marker as unstamped — the read is advisory, never load-bearing', function (): void {
    // A tier fenced from the config bucket 403s; it simply doesn't get the
    // warning rather than aborting its (read-only) run.
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('denied', new Command('GetObject'), ['code' => 'AccessDenied', 'response' => new Response(403)]),
    ], $captured);

    expect(EnvironmentVersion::stamped())->toBeNull()
        ->and(EnvironmentVersion::skewWarnings('1.2.0'))->toBeEmpty();
});
