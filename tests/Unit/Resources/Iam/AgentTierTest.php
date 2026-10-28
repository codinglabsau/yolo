<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Iam\AgentRole;
use Codinglabs\Yolo\Resources\Iam\AgentUser;
use Codinglabs\Yolo\Resources\Iam\AgentPolicy;
use Codinglabs\Yolo\Resources\Iam\AgentsGroup;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

function noSuchEntity(): Closure
{
    return fn ($command): IamException => new IamException('not found', $command, ['code' => 'NoSuchEntity']);
}

it('names every agent-tier resource per environment', function (): void {
    expect((new AgentPolicy())->name())->toBe('yolo-testing-agent')
        ->and((new AgentRole())->name())->toBe('yolo-testing-agent-role')
        ->and((new AgentsGroup())->name())->toBe('yolo-testing-agents')
        ->and((new AgentUser())->name())->toBe('yolo-testing-agent-user');

    foreach ([new AgentPolicy(), new AgentRole(), new AgentsGroup(), new AgentUser()] as $resource) {
        expect($resource->scope())->toBe(Scope::Env);
    }
});

it('reads exactly what the observer reads, minus the database tunnel', function (): void {
    $agent = (new AgentPolicy())->document()['Statement'];
    $observer = (new ObserverPolicy())->document()['Statement'];

    $actions = collect($agent)->flatMap(fn (array $statement): array => (array) $statement['Action']);

    expect($actions)->not->toContain('ssm:StartSession')
        ->and($actions)->not->toContain('ssm:TerminateSession');

    // Everything else is inherited verbatim — the session statements are the only cut.
    expect($agent)->toBe(array_values(array_slice($observer, 0, count($observer) - count((new ObserverPolicy())->sessionStatements()))));
});

it('trusts an MFA-carrying assume that may stamp a source identity', function (): void {
    $statement = (new AgentRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement['Principal'])->toBe(['AWS' => 'arn:aws:iam::111111111111:root'])
        ->and($statement['Action'])->toBe(['sts:AssumeRole', 'sts:SetSourceIdentity'])
        ->and($statement['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
});

it('grants the agent user the stamped assume and its MFA lookup — and no self-service', function (): void {
    $document = (new AgentsGroup())->document();

    expect($document['Statement'])->toHaveCount(2);

    expect($document['Statement'][0])->toBe([
        'Effect' => 'Allow',
        'Action' => ['sts:AssumeRole', 'sts:SetSourceIdentity'],
        'Resource' => 'arn:aws:iam::111111111111:role/yolo-testing-agent-role',
        'Condition' => ['Null' => ['sts:SourceIdentity' => 'false']],
    ]);

    // Holding the shared key must not let anyone cut a second one outside the vault.
    $actions = collect($document['Statement'])->flatMap(fn (array $statement): array => (array) $statement['Action']);
    expect($actions)->not->toContain('iam:CreateAccessKey')
        ->and($actions)->not->toContain('iam:DeactivateMFADevice')
        ->and($actions->all())->toContain('iam:ListMFADevices');
});

it('reports a missing agent user without throwing, so the first plan survives', function (): void {
    $captured = [];
    bindRoutedIamClient(['GetUser' => noSuchEntity()], $captured);

    expect((new AgentUser())->exists())->toBeFalse();
});

it('joins the agents group on create', function (): void {
    $captured = [];
    bindRoutedIamClient([], $captured);

    (new AgentUser())->create();

    expect(array_column($captured, 'name'))->toBe(['CreateUser', 'AddUserToGroup'])
        ->and($captured[0]['args']['UserName'])->toBe('yolo-testing-agent-user')
        ->and($captured[1]['args']['GroupName'])->toBe('yolo-testing-agents');
});

it('pins membership to the agents group, removing any other', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListGroupsForUser' => new Result(['Groups' => [['GroupName' => 'yolo-testing-admins']]]),
    ], $captured);

    $changes = (new AgentUser())->synchroniseConfiguration(apply: true);

    expect($changes)->toHaveCount(2);

    $writes = collect($captured)->reject(fn (array $call): bool => $call['name'] === 'ListGroupsForUser')->values();
    expect($writes->pluck('name')->all())->toBe(['AddUserToGroup', 'RemoveUserFromGroup'])
        ->and($writes[0]['args']['GroupName'])->toBe('yolo-testing-agents')
        ->and($writes[1]['args']['GroupName'])->toBe('yolo-testing-admins');
});

it('records membership drift on the plan pass without writing', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListGroupsForUser' => new Result(['Groups' => []]),
    ], $captured);

    expect((new AgentUser())->synchroniseConfiguration(apply: false))->toHaveCount(1)
        ->and(array_column($captured, 'name'))->toBe(['ListGroupsForUser']);
});

it('is clean when the only membership is the agents group', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListGroupsForUser' => new Result(['Groups' => [['GroupName' => 'yolo-testing-agents']]]),
    ], $captured);

    expect((new AgentUser())->synchroniseConfiguration(apply: true))->toBe([]);
});

it('strips groups, keys and the MFA device before deleting the agent user', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListGroupsForUser' => new Result(['Groups' => [['GroupName' => 'yolo-testing-agents']]]),
        'ListAccessKeys' => new Result(['AccessKeyMetadata' => [['AccessKeyId' => 'AKIAOLDEXAMPLE000001']]]),
        'ListMFADevices' => new Result(['MFADevices' => [['SerialNumber' => 'arn:aws:iam::111111111111:mfa/yolo-testing-agent-user']]]),
    ], $captured);

    (new AgentUser())->delete();

    expect(collect($captured)->pluck('name')->reject(fn (string $name): bool => str_starts_with($name, 'List'))->values()->all())->toBe([
        'RemoveUserFromGroup',
        'DeleteAccessKey',
        'DeactivateMFADevice',
        'DeleteVirtualMFADevice',
        'DeleteUser',
    ]);
});
