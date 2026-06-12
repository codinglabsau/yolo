<?php

use Aws\Result;
use Laravel\Prompts\Key;
use Codinglabs\Yolo\Paths;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Commands\EnvPullCommand;
use Codinglabs\Yolo\Commands\EnvPushCommand;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

afterEach(function (): void {
    foreach ([Paths::base('.env.testing'), Paths::base('.env.testing.tmp')] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('pulls the app env file naming both ends of the transfer', function (): void {
    Prompt::fake();

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "APP_KEY=secret\n"]),
    ], $captured);

    runEnvironmentFileCommand(new EnvPullCommand());

    $get = collect($captured)->firstWhere('name', 'GetObject');

    expect($get['args']['Bucket'])->toBe('yolo-111111111111-testing-my-app-config')
        ->and($get['args']['Key'])->toBe('.env.testing');

    expect(Prompt::content())
        ->toContain('s3://yolo-111111111111-testing-my-app-config/.env.testing')
        ->toContain('Downloaded successfully');
});

it('errors when no local app env file exists to push', function (): void {
    Prompt::fake();

    runEnvironmentFileCommand(new EnvPushCommand());

    expect(Prompt::content())->toContain('Could not find .env.testing');
});

it('diffs against the bucket copy, uploads on confirmation, and offers to delete the local file (default yes)', function (): void {
    // The mocked GetObject never writes the SaveAs sink, so the temporary
    // remote copy is pre-seeded to stand in for the bucket's current state.
    Prompt::fake(['y', Key::ENTER, Key::ENTER]);
    file_put_contents(Paths::base('.env.testing'), "APP_KEY=rotated\n");
    file_put_contents(Paths::base('.env.testing.tmp'), "APP_KEY=old\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(),
        'PutObject' => new Result(),
    ], $captured);

    runEnvironmentFileCommand(new EnvPushCommand());

    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-my-app-config')
        ->and($put['args']['Key'])->toBe('.env.testing')
        ->and((string) $put['args']['Body'])->toBe("APP_KEY=rotated\n");

    expect(file_exists(Paths::base('.env.testing')))->toBeFalse()
        ->and(file_exists(Paths::base('.env.testing.tmp')))->toBeFalse();

    expect(Prompt::content())->toContain('s3://yolo-111111111111-testing-my-app-config/.env.testing');
});

it('uploads nothing when the diff is declined and keeps the local file', function (): void {
    // The upload confirm defaults to yes — declining takes an explicit 'n'.
    Prompt::fake(['n', Key::ENTER]);
    file_put_contents(Paths::base('.env.testing'), "APP_KEY=rotated\n");
    file_put_contents(Paths::base('.env.testing.tmp'), "APP_KEY=old\n");

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(),
    ], $captured);

    runEnvironmentFileCommand(new EnvPushCommand());

    expect(array_column($captured, 'name'))->not->toContain('PutObject');
    expect(file_exists(Paths::base('.env.testing')))->toBeTrue();
    expect(Prompt::content())->toContain('Nothing uploaded');
});
