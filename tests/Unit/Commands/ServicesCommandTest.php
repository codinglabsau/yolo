<?php

use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Commands\ServicesCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drive ServicesCommand::handle() directly. Prompts run non-interactively, so
 * tests exercise the --json / --add / --remove paths (the interactive picker
 * isn't driven here). Returns the exit code and the command's own output buffer.
 *
 * @param  array<string, string|bool|array<int, string>>  $options
 * @return array{exit: int, output: string}
 */
function invokeServices(array $options = [], string $environment = 'testing'): array
{
    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());

    $command = new ServicesCommand();

    $input = ['environment' => $environment];

    foreach ($options as $name => $value) {
        $input['--' . $name] = $value;
    }

    $command->input = new ArrayInput($input, $command->getDefinition());
    $command->output = $output = new BufferedOutput();

    return ['exit' => $command->handle(), 'output' => $output->fetch()];
}

function offeringTypesense(array $claims = [], array $clusters = []): array
{
    return [
        'manifest' => "domain: example.com\nservices:\n  typesense:\n    version: '29.0'\n    nodes: 3\n",
        'claims' => $claims,
        'clusters' => $clusters,
    ];
}

it('is named services and is registered', function (): void {
    expect((new ServicesCommand())->getName())->toBe('services')
        ->and((new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'])->toContain(ServicesCommand::class);
});

it('derives the lifecycle display state from the two-key gate', function (): void {
    expect(ServicesCommand::displayState(envBacked: true, offered: true, used: true, unpublished: false))->toBe('provision')
        ->and(ServicesCommand::displayState(true, true, false, false))->toBe('teardown')
        ->and(ServicesCommand::displayState(true, true, false, true))->toBe('retain')
        ->and(ServicesCommand::displayState(true, false, true, false))->toBe('conflict')
        ->and(ServicesCommand::displayState(true, false, false, false))->toBe('off')
        ->and(ServicesCommand::displayState(false, false, true, false))->toBe('app-side');
});

it('summarises an offer for the table', function (): void {
    expect(ServicesCommand::offerSummary(['version' => '29.0', 'nodes' => 2]))->toBe('version=29.0 nodes=2')
        ->and(ServicesCommand::offerSummary([]))->toBe('✓')
        ->and(ServicesCommand::offerSummary(null))->toBe('✓');
});

it('reports the service state as json', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld(offeringTypesense(claims: ['convict' => ['typesense']], clusters: ['convict' => true]), $captured);

    $result = invokeServices(options: ['json' => true]);
    $typesense = collect(json_decode($result['output'], true))->firstWhere('service', 'typesense');

    expect($result['exit'])->toBe(0)
        ->and($typesense['offered'])->toBeTrue()
        ->and($typesense['usedBy'])->toBe(['convict'])
        ->and($typesense['state'])->toBe('provision');
});

it('adds a service offer and uploads the env manifest', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "domain: example.com\nservices: {}\n", 'claims' => [], 'clusters' => []], $captured);

    $result = invokeServices(options: ['add' => 'typesense', 'set' => ['version=29.0', 'nodes=3']]);
    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($result['exit'])->toBe(0)
        ->and($put)->not->toBeNull()
        ->and($put['args']['Body'])->toContain('typesense')
        ->and($put['args']['Body'])->toContain('29.0');
});

it('refuses to withdraw a service a running app still uses', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld(offeringTypesense(claims: ['convict' => ['typesense']], clusters: ['convict' => true]), $captured);

    $result = invokeServices(options: ['remove' => 'typesense']);

    expect($result['exit'])->toBe(1)
        ->and(collect($captured)->where('name', 'PutObject'))->toBeEmpty();
});

it('withdraws an unused service offer', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld(offeringTypesense(claims: [], clusters: []), $captured);

    $result = invokeServices(options: ['remove' => 'typesense']);
    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($result['exit'])->toBe(0)
        ->and($put)->not->toBeNull()
        ->and($put['args']['Body'])->not->toContain('typesense');
});

it('rejects offering an app-side-only service', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'tasks' => ['web' => []]]);

    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "domain: example.com\nservices: {}\n", 'claims' => [], 'clusters' => []], $captured);

    $result = invokeServices(options: ['add' => 'rekognition', 'set' => []]);

    expect($result['exit'])->toBe(1)
        ->and(collect($captured)->where('name', 'PutObject'))->toBeEmpty();
});
