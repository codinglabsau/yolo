<?php

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;

function environmentFilesHarness(): object
{
    return new class()
    {
        use ManagesEnvironmentFiles {
            download as public;
            sharedEnvFilename as public;
            sharedEnvLocalPath as public;
        }
    };
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('names the env-shared .env identically in the bucket and on disk, environment included', function (): void {
    $harness = environmentFilesHarness();

    expect($harness->sharedEnvFilename())->toBe('.env.environment.testing')
        ->and($harness->sharedEnvLocalPath())->toEndWith('/.env.environment.testing');
});

it('writes the downloaded body to the local path on success', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=secret\n"]),
    ], $captured);

    $path = BASE_PATH . '/.env.environment.download-success';

    expect(environmentFilesHarness()->download('.env.environment.testing', $path))->toBeTrue()
        ->and(file_get_contents($path))->toBe("TYPESENSE_API_KEY=secret\n");

    unlink($path);
});

it('reports a missing object without touching an existing local working copy', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not Found', new Command('GetObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    $path = BASE_PATH . '/.env.environment.download-missing';
    file_put_contents($path, 'unpushed local edits');

    expect(environmentFilesHarness()->download('.env.environment.testing', $path))->toBeFalse()
        ->and(file_get_contents($path))->toBe('unpushed local edits');

    unlink($path);
});

it('throws on a denied download rather than reporting the object missing — and leaves the local copy alone', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Access Denied', new Command('GetObject'), [
            'code' => 'AccessDenied',
            'response' => new Response(403),
        ]),
    ], $captured);

    $path = BASE_PATH . '/.env.environment.download-denied';
    file_put_contents($path, 'unpushed local edits');

    expect(fn (): bool => environmentFilesHarness()->download('.env.environment.testing', $path))
        ->toThrow(S3Exception::class);
    expect(file_get_contents($path))->toBe('unpushed local edits');

    unlink($path);
});
