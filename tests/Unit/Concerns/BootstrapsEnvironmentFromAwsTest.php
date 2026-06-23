<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Laravel\Prompts\Key;
use Aws\CommandInterface;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use GuzzleHttp\Psr7\Response;
use Aws\Command as AwsCommand;
use GuzzleHttp\Promise\Create;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Commands\DestroyEnvironmentCommand;

function bindBootstrapStsClient(string $account): void
{
    $mock = new MockHandler();
    $mock->append(new Result(['Account' => $account]));

    Helpers::app()->instance('sts', new StsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Bind a command-routed mock S3 client (a sibling test file's bindMockS3Client
 * isn't loaded for this file's run). A command value is a Result or a Throwable
 * returned as a rejection; an unset command defaults to an empty Result.
 *
 * @param  array<string, Result|Throwable>  $byCommand
 */
function bindBootstrapS3Client(array $byCommand): void
{
    $mock = new class($byCommand) extends MockHandler
    {
        public function __construct(protected array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $entry = $this->byCommand[$cmd->getName()] ?? new Result();

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function bootstrapCommand(string $environment = 'typesense', bool $interactive = false): DestroyEnvironmentCommand
{
    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => $environment], $command->getDefinition());
    $input->setInteractive($interactive);
    $command->input = $input;
    $command->output = new BufferedOutput();

    return $command;
}

/** Invoke a protected method on the command under test. */
function invokeBootstrap(DestroyEnvironmentCommand $command, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($command, $method))->invoke($command, ...$args);
}

beforeEach(function (): void {
    Prompt::setOutput(new BufferedOutput());
});

afterEach(function (): void {
    Manifest::flushHydration();
    putenv('YOLO_TYPESENSE_AWS_PROFILE');
    putenv('YOLO_TYPESENSE_AWS_REGION');
    writeManifest([]);
});

it('reconstructs the environment from the profile, STS and the S3 env manifest', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    putenv('YOLO_TYPESENSE_AWS_PROFILE=my-profile');
    putenv('YOLO_TYPESENSE_AWS_REGION=ap-southeast-2');

    bindBootstrapStsClient('222222222222');
    bindBootstrapS3Client([
        'GetObject' => new Result(['Body' => "domain: search.example.com\nservices:\n  typesense:\n    version: \"30.2\"\n"]),
    ]);

    $result = invokeBootstrap(bootstrapCommand(), 'bootstrapEnvironmentFromAws', 'typesense');

    expect($result)->toBeNull()
        ->and(Manifest::environmentExists('typesense'))->toBeTrue()
        ->and(Manifest::get('account-id'))->toBe('222222222222')
        ->and(Manifest::get('region'))->toBe('ap-southeast-2')
        ->and(Manifest::get('domain'))->toBe('search.example.com')
        ->and(Manifest::services())->toBe(['typesense'])
        // The app name comes from yolo.yml on disk (env-scope teardown never uses it,
        // but the base requires a declared name).
        ->and(Manifest::name())->toBe('my-app');
});

it('aborts when the typed account ID does not match the resolved account', function (): void {
    putenv('YOLO_TYPESENSE_AWS_PROFILE=my-profile');
    putenv('YOLO_TYPESENSE_AWS_REGION=ap-southeast-2');

    bindBootstrapStsClient('222222222222');
    Prompt::fake(['999999999999', Key::ENTER]);

    // The which-account safety gate: a mismatched typed account ID cancels the whole
    // teardown before any resource is touched.
    expect(invokeBootstrap(bootstrapCommand(interactive: true), 'bootstrapEnvironmentFromAws', 'typesense'))
        ->toBe(DestroyEnvironmentCommand::FAILURE);
});

it('proceeds when the typed account ID matches the resolved account', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    putenv('YOLO_TYPESENSE_AWS_PROFILE=my-profile');
    putenv('YOLO_TYPESENSE_AWS_REGION=ap-southeast-2');

    bindBootstrapStsClient('222222222222');
    bindBootstrapS3Client([
        'GetObject' => new Result(['Body' => "domain: search.example.com\nservices:\n  typesense:\n    version: \"30.2\"\n"]),
    ]);
    Prompt::fake(['222222222222', Key::ENTER]);

    $result = invokeBootstrap(bootstrapCommand(interactive: true), 'bootstrapEnvironmentFromAws', 'typesense');

    expect($result)->toBeNull()
        ->and(Manifest::get('account-id'))->toBe('222222222222')
        ->and(Manifest::get('domain'))->toBe('search.example.com');
});

it('fails when the published env manifest is absent (env gone or never synced)', function (): void {
    putenv('YOLO_TYPESENSE_AWS_PROFILE=my-profile');
    putenv('YOLO_TYPESENSE_AWS_REGION=ap-southeast-2');

    bindBootstrapStsClient('222222222222');
    bindBootstrapS3Client([
        'HeadObject' => new S3Exception('Not Found', new AwsCommand('HeadObject'), ['response' => new Response(404)]),
    ]);

    expect(invokeBootstrap(bootstrapCommand(), 'bootstrapEnvironmentFromAws', 'typesense'))
        ->toBe(DestroyEnvironmentCommand::FAILURE);
});

it('fails non-interactively when no AWS profile can be resolved', function (): void {
    putenv('YOLO_TYPESENSE_AWS_PROFILE');

    expect(invokeBootstrap(bootstrapCommand(), 'bootstrapEnvironmentFromAws', 'typesense'))
        ->toBe(DestroyEnvironmentCommand::FAILURE);
});

it('prefers an explicit YOLO_<ENV>_AWS_REGION over the profile config', function (): void {
    putenv('YOLO_TYPESENSE_AWS_REGION=eu-central-1');
    Helpers::app()->instance('environment', 'typesense');

    expect(invokeBootstrap(bootstrapCommand(), 'resolveRegion', 'my-profile'))->toBe('eu-central-1');
});

it('synthesises a single-environment manifest, dropping null/empty keys', function (): void {
    $manifest = invokeBootstrap(bootstrapCommand(), 'synthesiseManifest', 'my-app', 'typesense', '222222222222', 'ap-southeast-2', null, []);

    expect($manifest)->toBe([
        'name' => 'my-app',
        'environments' => [
            'typesense' => ['account-id' => '222222222222', 'region' => 'ap-southeast-2'],
        ],
    ]);
});

it('does not bootstrap from AWS when the environment is still declared in yolo.yml', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    // bootstrapEnvironment() is the command's gate: declared locally → null (no AWS).
    $command = new DestroyEnvironmentCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());

    expect(invokeBootstrap($command, 'bootstrapEnvironment'))->toBeNull();
});
