<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Laravel\Prompts\Prompt;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Commands\EnvironmentManifestPullCommand;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

afterEach(function (): void {
    if (file_exists(EnvManifest::localPath())) {
        unlink(EnvManifest::localPath());
    }
});

it('downloads the manifest naming both ends of the transfer', function (): void {
    Prompt::fake();

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "services: {  }\n"]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPullCommand());

    expect(file_get_contents(EnvManifest::localPath()))->toBe("services: {  }\n");
    expect(Prompt::content())
        ->toContain('s3://yolo-111111111111-testing-config/yolo-environment-testing.yml')
        ->toContain('Downloaded successfully');
});

it('errors with seed instructions when no manifest exists in the bucket yet', function (): void {
    Prompt::fake();

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not Found', new Command('GetObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPullCommand());

    expect(file_exists(EnvManifest::localPath()))->toBeFalse();
    expect(Prompt::content())->toContain('run `yolo sync:environment testing` to seed it first');
});
