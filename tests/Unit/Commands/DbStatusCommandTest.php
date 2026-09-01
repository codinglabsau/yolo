<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Commands\DbStatusCommand;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Contracts\ReadsEnvironment;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drive DbStatusCommand::handle() directly with a routed S3 mock.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function invokeDbStatus(array $options = []): array
{
    Prompt::interactive(false);
    Prompt::setOutput($promptOutput = new BufferedOutput());

    $command = new DbStatusCommand();

    $input = ['environment' => 'testing'];

    foreach ($options as $name => $value) {
        $input['--' . $name] = $value;
    }

    $command->input = new ArrayInput($input, $command->getDefinition());
    $command->output = new BufferedOutput();

    $exit = $command->handle();

    return [$exit, $command->output->fetch(), $promptOutput->fetch()];
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is named db:status', function (): void {
    expect((new DbStatusCommand())->getName())->toBe('db:status');
});

it('is registered in the application', function (): void {
    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];

    expect($commands)->toContain(DbStatusCommand::class);
});

it('runs under the env-scoped observer tier — S3 reads only', function (): void {
    $command = new DbStatusCommand();

    expect($command)->toBeInstanceOf(ReadOnlyCommand::class)
        ->and($command)->toBeInstanceOf(ReadsEnvironment::class);
});

it('maps every published claim to its declared database, sorted by app', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result([
            'Contents' => [
                ['Key' => 'apps/beta-app.yml'],
                ['Key' => 'apps/alpha-app.yml'],
                ['Key' => 'apps/not-a-claim.txt'],
            ],
        ]),
        'GetObject' => [
            new Result(['Body' => Yaml::dump(['name' => 'beta-app', 'database' => 'beta-db.abc.rds.amazonaws.com', 'services' => []])]),
            new Result(['Body' => Yaml::dump(['name' => 'alpha-app', 'services' => []])]),
        ],
    ], $captured);

    [$exit, $output] = invokeDbStatus(['json' => true]);

    expect($exit)->toBe(0);

    $json = json_decode($output, true);

    expect($json['environment'])->toBe('testing')
        ->and($json['apps'])->toBe([
            'alpha-app' => null,
            'beta-app' => 'beta-db.abc.rds.amazonaws.com',
        ]);
});

it('renders the assignment table with a dash for claims without a database', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result([
            'Contents' => [['Key' => 'apps/my-app.yml']],
        ]),
        'GetObject' => new Result(['Body' => Yaml::dump(['name' => 'my-app', 'services' => []])]),
    ], $captured);

    [$exit, $output, $promptOutput] = invokeDbStatus();

    expect($exit)->toBe(0)
        ->and($promptOutput)->toContain('my-app')
        ->and($promptOutput)->toContain('—');
});

it('falls back to the object key for an app whose claim is unreadable', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result([
            'Contents' => [['Key' => 'apps/legacy-app.yml']],
        ]),
        'GetObject' => new Result(['Body' => 'services']),
    ], $captured);

    [$exit, $output] = invokeDbStatus(['json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['apps'])->toBe(['legacy-app' => null]);
});

it('reads an unprovisioned environment as no claims, not an error', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new S3Exception('NoSuchBucket', new Command('ListObjectsV2'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    [$exit, $output, $promptOutput] = invokeDbStatus();

    expect($exit)->toBe(0)
        ->and($promptOutput)->toContain('No published app claims');
});

it('paginates the claim listing across continuation tokens', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => [
            new Result([
                'Contents' => [['Key' => 'apps/first-app.yml']],
                'IsTruncated' => true,
                'NextContinuationToken' => 'token-1',
            ]),
            new Result([
                'Contents' => [['Key' => 'apps/second-app.yml']],
            ]),
        ],
        'GetObject' => [
            new Result(['Body' => Yaml::dump(['name' => 'first-app', 'database' => 'shared-db', 'services' => []])]),
            new Result(['Body' => Yaml::dump(['name' => 'second-app', 'database' => 'shared-db', 'services' => []])]),
        ],
    ], $captured);

    [$exit, $output] = invokeDbStatus(['json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['apps'])->toBe([
            'first-app' => 'shared-db',
            'second-app' => 'shared-db',
        ]);

    $listCalls = collect($captured)->where('name', 'ListObjectsV2');

    expect($listCalls)->toHaveCount(2)
        ->and($listCalls->last()['args']['ContinuationToken'])->toBe('token-1');
});
