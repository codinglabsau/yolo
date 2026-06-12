<?php

use Aws\Result;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Commands\EnvironmentManifestPushCommand;

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

it('errors with pull instructions when no local working copy exists', function (): void {
    Prompt::fake();

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(Prompt::content())->toContain('pull it first with `yolo environment:manifest:pull testing`');
});

it('rejects a misshapen manifest before anything touches the bucket', function (): void {
    Prompt::fake();
    file_put_contents(EnvManifest::localPath(), "made-up-key: true\n");

    $captured = [];
    bindRoutedS3Client([], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(Prompt::content())->toContain('made-up-key');
});

it('refuses to overwrite a bucket manifest written by a newer release', function (): void {
    Prompt::fake();
    file_put_contents(EnvManifest::localPath(), "services: {  }\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "services:\n  from-the-future: {  }\n"]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(Prompt::content())->toContain('update codinglabsau/yolo before pushing');
});

it('diffs, uploads on confirmation, and offers to delete the local copy (default yes)', function (): void {
    // Both confirms default to yes — ENTER accepts the upload, ENTER
    // accepts deleting the local copy.
    Prompt::fake([Key::ENTER, Key::ENTER]);
    file_put_contents(EnvManifest::localPath(), "domain: example.test\nservices: {  }\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "services: {  }\n"]),
        'PutObject' => new Result(),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($put['args']['Key'])->toBe('yolo-environment-testing.yml')
        ->and((string) $put['args']['Body'])->toContain('domain: example.test');

    expect(file_exists(EnvManifest::localPath()))->toBeFalse();
    expect(Prompt::content())->toContain('s3://yolo-111111111111-testing-config/yolo-environment-testing.yml');
});

it('uploads nothing when the diff is declined', function (): void {
    // The upload confirm defaults to yes — declining takes an explicit 'n'.
    Prompt::fake(['n', Key::ENTER]);
    file_put_contents(EnvManifest::localPath(), "domain: example.test\nservices: {  }\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "services: {  }\n"]),
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(file_exists(EnvManifest::localPath()))->toBeTrue();
    expect(Prompt::content())->toContain('Nothing uploaded');
});

it('refuses to remove a service a running app still uses, naming the app', function (): void {
    Prompt::fake();
    file_put_contents(EnvManifest::localPath(), "services: {  }\n");

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => true],
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(Prompt::content())->toContain("Can't remove services.ivs")
        ->toContain('my-app');
});

it('refuses to remove a service while a running app has not published what it uses', function (): void {
    Prompt::fake();
    file_put_contents(EnvManifest::localPath(), "services: {  }\n");

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => [],
        'clusters' => ['my-app' => true],
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(Prompt::content())->toContain("Can't remove services.ivs")
        ->toContain("hasn't deployed");
});

it('allows removing a service once no running app uses it', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER]);
    file_put_contents(EnvManifest::localPath(), "services: {  }\n");

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->toContain('PutObject');
});

it('a dead app does not block removing a service', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER]);
    file_put_contents(EnvManifest::localPath(), "services: {  }\n");

    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => false],
    ], $captured);

    runEnvironmentFileCommand(new EnvironmentManifestPushCommand());

    expect(array_column($captured, 'name'))->toContain('PutObject');
});
