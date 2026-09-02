<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Codinglabs\Yolo\Change;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Services\TypesenseTaskDefinition;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseNodesStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

const TASK_DEFINITION_OFFER = "domain: example.com.au\nservices:\n  typesense:\n    version: \"30.2\"\n    cpu: 256\n    memory: 1024\n";

/**
 * A provisioned cluster world: the offer + a claim, the admin key already
 * minted, and the execution role resolvable — everything the desired
 * revision renders from.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $iamCaptured
 */
function bindTaskDefinitionWorld(array &$iamCaptured, bool $adminKeyMinted = true): void
{
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => TASK_DEFINITION_OFFER,
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
        'sharedEnv' => $adminKeyMinted ? "TYPESENSE_API_KEY=admin-key\n" : null,
    ], $captured);

    $role = (new EcsExecutionRole())->name();

    bindRoutedIamClient([
        'ListRoles' => new Result(['Roles' => [['RoleName' => $role, 'Arn' => 'arn:aws:iam::111111111111:role/' . $role]], 'IsTruncated' => false]),
    ], $iamCaptured);
}

/**
 * The ECS world: the lifecycle liveness reads, every declared node already
 * running (each read twice by partition — exists(), then the revision), the
 * surplus probe finding nothing, and the family's registered revision.
 *
 * @param  array<int, string>  $revisionByDescribe
 * @param  array<string, mixed>  $liveTaskDefinition
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindTaskDefinitionEcsWorld(array $revisionByDescribe, array $liveTaskDefinition, array &$captured): void
{
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
        'DescribeServices' => [
            ...array_map(fn (string $revision): Result => new Result(['services' => [[
                'status' => 'ACTIVE',
                'taskDefinition' => $revision,
            ]]]), $revisionByDescribe),
            new AwsException('nope', new Command('DescribeServices'), ['code' => 'ServiceNotFoundException']),
        ],
        'DescribeTaskDefinition' => new Result(['taskDefinition' => $liveTaskDefinition]),
    ], $captured);
}

/**
 * A registered revision that matches the desired payload exactly — what AWS
 * hands back after the task-definition step has registered it.
 *
 * @return array<string, mixed>
 */
function registeredDesiredRevision(): array
{
    return [...TypesenseTaskDefinition::desired(), 'taskDefinitionArn' => 'arn:td/1', 'revision' => 1];
}

it('matches a registered revision by subset, ignoring tags and the fields AWS derives', function (): void {
    $desired = [
        'family' => 'yolo-testing-typesense',
        'cpu' => '256',
        'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:30.2-abc']],
        'tags' => [['key' => 'Name', 'value' => 'x']],
    ];

    // Extra live fields (ARN, revision, derived compatibilities) and the
    // missing tags don't count; a numeric cpu compares as its string form.
    expect(TypesenseTaskDefinition::matches($desired, [
        'family' => 'yolo-testing-typesense',
        'cpu' => 256,
        'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:30.2-abc', 'cpu' => 0]],
        'taskDefinitionArn' => 'arn:td/1',
        'revision' => 1,
    ]))->toBeTrue();

    // A changed image, a missing key, or a scalar where a list is expected all drift.
    expect(TypesenseTaskDefinition::matches($desired, [
        'family' => 'yolo-testing-typesense',
        'cpu' => '256',
        'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:29.0-old']],
    ]))->toBeFalse()
        ->and(TypesenseTaskDefinition::matches($desired, ['family' => 'yolo-testing-typesense', 'cpu' => '256']))->toBeFalse()
        ->and(TypesenseTaskDefinition::matches($desired, ['family' => 'yolo-testing-typesense', 'cpu' => '256', 'containerDefinitions' => 'nope']))->toBeFalse();
});

it('reports a registration pending while the desired revision cannot render yet', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured, adminKeyMinted: false);

    $ecsCaptured = [];
    bindRoutedEcsClient([
        'DescribeTaskDefinition' => new AwsException('nope', new Command('DescribeTaskDefinition'), ['code' => 'ClientException']),
    ], $ecsCaptured);

    // No admin key → no image tag → the payload throws, which reads as pending
    // (the earlier steps mint the key and build the image in the same sync).
    expect(fn (): array => TypesenseTaskDefinition::desired())->toThrow(ResourceDoesNotExistException::class)
        ->and(TypesenseTaskDefinition::registrationPending())->toBeTrue();
});

it('the task-definition step plans a new revision when the registered one drifted, and registers it on apply', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured);

    $ecsCaptured = [];
    bindTaskDefinitionEcsWorld(
        revisionByDescribe: [],
        liveTaskDefinition: ['taskDefinitionArn' => 'arn:td/1', 'revision' => 1, 'family' => 'yolo-testing-typesense', 'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:29.0-old']]],
        captured: $ecsCaptured,
    );

    $planned = new SyncTypesenseTaskDefinitionStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and($planned->changes())->toHaveCount(1)
        ->and($planned->changes()[0]->from)->toBe('revision 1')
        ->and(array_column($ecsCaptured, 'name'))->not->toContain('RegisterTaskDefinition');

    expect((new SyncTypesenseTaskDefinitionStep())([]))->toBe(StepResult::SYNCED);

    $registered = collect($ecsCaptured)->firstWhere('name', 'RegisterTaskDefinition');
    expect($registered['args']['family'])->toBe('yolo-testing-typesense')
        ->and($registered['args']['containerDefinitions'][0]['image'])->toEndWith('/yolo-testing-typesense:' . Typesense::imageTag());
});

it('the task-definition step stays SYNCED while the registered revision matches the desired payload', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured);

    $ecsCaptured = [];
    bindTaskDefinitionEcsWorld(revisionByDescribe: [], liveTaskDefinition: registeredDesiredRevision(), captured: $ecsCaptured);

    $step = new SyncTypesenseTaskDefinitionStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::SYNCED)
        ->and($step->changes())->toBe([]);
});

it('the task-definition step reports pending on a greenfield plan and hard-fails an unrenderable apply', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured, adminKeyMinted: false);

    $ecsCaptured = [];
    bindRoutedEcsClient([
        'ListClusters' => new Result(['clusterArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app']]),
        'ListTasks' => new Result(['taskArns' => ['arn:aws:ecs:ap-southeast-2:111111111111:task/x']]),
        'DescribeTaskDefinition' => new AwsException('nope', new Command('DescribeTaskDefinition'), ['code' => 'ClientException']),
    ], $ecsCaptured);

    $planned = new SyncTypesenseTaskDefinitionStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and($planned->changes()[0]->from)->toBe('absent');

    expect(fn (): StepResult => (new SyncTypesenseTaskDefinitionStep())([]))
        ->toThrow(ResourceDoesNotExistException::class, 'must exist by now');
});

it('the nodes step plans every existing node stale in the same sync that registers a new revision', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured);

    // Nodes 0 and 2 run the family's CURRENT latest (node 1 lags it) — on the
    // live latest alone only node 1 reads stale, so the other two would be
    // pruned from apply and land one sync behind the revision this run
    // registers. The pending registration has to stale all three.
    $ecsCaptured = [];
    bindTaskDefinitionEcsWorld(
        revisionByDescribe: ['arn:td/1', 'arn:td/1', 'arn:td/0', 'arn:td/0', 'arn:td/1', 'arn:td/1'],
        liveTaskDefinition: ['taskDefinitionArn' => 'arn:td/1', 'revision' => 1, 'family' => 'yolo-testing-typesense', 'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:29.0-old']]],
        captured: $ecsCaptured,
    );

    $planned = new SyncTypesenseNodesStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and(array_map(fn (Change $change): string => $change->attribute, $planned->changes()))
        ->toBe(['yolo-testing-typesense-0', 'yolo-testing-typesense-1', 'yolo-testing-typesense-2'])
        ->and(array_column($ecsCaptured, 'name'))->not->toContain('UpdateService');
});

it('the nodes step plans clean once the registered revision matches and every node runs it', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured);

    $ecsCaptured = [];
    bindTaskDefinitionEcsWorld(
        revisionByDescribe: array_fill(0, 6, 'arn:td/1'),
        liveTaskDefinition: registeredDesiredRevision(),
        captured: $ecsCaptured,
    );

    $planned = new SyncTypesenseNodesStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::SYNCED)
        ->and($planned->changes())->toBe([]);
});

it('the nodes step trusts the live latest alone under the deploy gate, never rendering the fenced desired revision', function (): void {
    $iamCaptured = [];
    bindTaskDefinitionWorld($iamCaptured);

    // The registered revision drifted, but the gate skips the task-definition
    // step so nothing registers during its check — and rendering the desired
    // payload would read the admin key the deployer tier is fenced from.
    $ecsCaptured = [];
    bindTaskDefinitionEcsWorld(
        revisionByDescribe: array_fill(0, 6, 'arn:td/1'),
        liveTaskDefinition: ['taskDefinitionArn' => 'arn:td/1', 'revision' => 1, 'family' => 'yolo-testing-typesense', 'containerDefinitions' => [['name' => 'typesense', 'image' => 'repo:29.0-old']]],
        captured: $ecsCaptured,
    );

    $planned = new SyncTypesenseNodesStep();
    $result = DeployCheck::during(fn (): StepResult => $planned(['dry-run' => true]));

    expect($result)->toBe(StepResult::SYNCED)
        ->and($planned->changes())->toBe([])
        ->and($iamCaptured)->toBe([]);
});
