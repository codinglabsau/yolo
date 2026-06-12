<?php

use Aws\Result;
use Aws\Command;
use Laravel\Prompts\Key;
use Codinglabs\Yolo\Paths;
use Laravel\Prompts\Prompt;
use GuzzleHttp\Psr7\Response;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Commands\EnvironmentEnvPullCommand;
use Codinglabs\Yolo\Commands\EnvironmentEnvPushCommand;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

afterEach(function (): void {
    if (file_exists(Paths::base('.env.environment.testing'))) {
        unlink(Paths::base('.env.environment.testing'));
    }
});

it('downloads the env-shared .env naming both ends of the transfer', function (): void {
    Prompt::fake();

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=secret\n"]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentEnvPullCommand());

    expect(file_get_contents(Paths::base('.env.environment.testing')))->toBe("TYPESENSE_API_KEY=secret\n");
    expect(Prompt::content())
        ->toContain('s3://yolo-111111111111-testing-config/.env.environment.testing')
        ->toContain('Downloaded successfully');
});

it('errors with create-and-push instructions when no env-shared .env exists yet', function (): void {
    Prompt::fake();

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not Found', new Command('GetObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentEnvPullCommand());

    expect(file_exists(Paths::base('.env.environment.testing')))->toBeFalse();
    expect(Prompt::content())->toContain('push it with `yolo environment:env:push testing`');
});

it('errors when no local env-shared .env exists to push', function (): void {
    Prompt::fake();

    runEnvironmentFileCommand(new EnvironmentEnvPushCommand());

    expect(Prompt::content())->toContain('.env.environment.testing');
});

it('diffs keys, uploads on confirmation, and offers to delete the local copy (default yes)', function (): void {
    // Both confirms default to yes — ENTER accepts the upload, ENTER
    // accepts deleting the local copy.
    Prompt::fake([Key::ENTER, Key::ENTER]);
    file_put_contents(Paths::base('.env.environment.testing'), "TYPESENSE_API_KEY=rotated\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=old\n"]),
        'PutObject' => new Result(),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentEnvPushCommand());

    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($put['args']['Key'])->toBe('.env.environment.testing')
        ->and((string) $put['args']['Body'])->toBe("TYPESENSE_API_KEY=rotated\n");

    expect(file_exists(Paths::base('.env.environment.testing')))->toBeFalse();
});

it('warns instead of diffing when the bucket holds no env-shared .env yet, then uploads', function (): void {
    // No remote copy: upload-anyway + delete-local, both yes-by-default.
    Prompt::fake([Key::ENTER, Key::ENTER]);
    file_put_contents(Paths::base('.env.environment.testing'), "TYPESENSE_API_KEY=first\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not Found', new Command('GetObject'), [
            'response' => new Response(404),
        ]),
        'PutObject' => new Result(),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentEnvPushCommand());

    expect(Prompt::content())->toContain('does not exist in the env config bucket yet');
    expect(collect($captured)->firstWhere('name', 'PutObject'))->not->toBeNull();
});
