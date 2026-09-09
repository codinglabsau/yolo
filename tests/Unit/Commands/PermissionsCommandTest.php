<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Iam\IamClient;
use Laravel\Prompts\Key;
use Aws\CommandInterface;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Aws\Iam\Exception\IamException;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\PermissionsCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An IAM client answering the permissions flow: one user, the tier groups all
 * provisioned, and yolo-users present or not per $usersGroupExists.
 *
 * @param  array<int, string>  $holds
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindPermissionsIamClient(array $holds, bool $usersGroupExists, array &$captured): void
{
    $mock = new class($holds, $usersGroupExists, $captured) extends MockHandler
    {
        /** @param array<int, string> $holds */
        public function __construct(
            protected array $holds,
            protected bool $usersGroupExists,
            protected array &$captured,
        ) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $args = $cmd->toArray();
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $args];

            return match ($cmd->getName()) {
                'GetGroup' => ($args['GroupName'] === 'yolo-users' && ! $this->usersGroupExists)
                    ? Create::rejectionFor(new IamException('no such entity', $cmd, [
                        'response' => new Response(404),
                        'code' => 'NoSuchEntity',
                    ]))
                    : Create::promiseFor(new Result([
                        'Group' => [
                            'GroupName' => $args['GroupName'],
                            'Arn' => sprintf('arn:aws:iam::111111111111:group/%s', $args['GroupName']),
                        ],
                        'Users' => [],
                    ])),
                'ListUsers' => Create::promiseFor(new Result([
                    'Users' => [['UserName' => 'ada']],
                ])),
                'ListGroupsForUser' => Create::promiseFor(new Result([
                    'Groups' => array_map(
                        fn (string $name): array => ['GroupName' => $name],
                        $this->holds,
                    ),
                ])),
                default => Create::promiseFor(new Result()),
            };
        }
    };

    Helpers::app()->instance('iam', new IamClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * Run the command's handle() directly against the mocked IAM client, skipping
 * the base execute() plumbing (auth, STS guard) like the other command tests.
 */
function runPermissionsCommand(string $environment = 'testing'): void
{
    $command = new PermissionsCommand();
    $command->input = new ArrayInput(
        ['environment' => $environment],
        $command->getDefinition(),
    );

    $command->handle();
}

/**
 * The group names an AddUserToGroup call was issued for, in order.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 * @return array<int, string>
 */
function addedGroups(array $captured): array
{
    return collect($captured)
        ->where('name', 'AddUserToGroup')
        ->pluck('args.GroupName')
        ->values()
        ->all();
}

beforeEach(function (): void {
    Prompt::setOutput(new BufferedOutput());

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        // A repository would provision a deployer role — and still no human grant
        // for it is offered, whatever the suite's git origin.
        'repository' => 'codinglabsau/example',
    ]);
});

it('grants the tier selected but not held, and revokes the held-but-unselected — only within the offerable set', function (): void {
    $offerable = ['yolo-prod-observers', 'yolo-prod-my-app-observers', 'yolo-prod-my-app-developers', 'yolo-prod-admins'];
    $current = ['yolo-prod-observers', 'yolo-prod-my-app-developers', 'some-other-team-group'];
    $selected = ['yolo-prod-observers', 'yolo-prod-admins'];

    $changes = PermissionsCommand::membershipChanges($offerable, $current, $selected);

    expect($changes['add'])->toBe(['yolo-prod-admins']);
    expect($changes['remove'])->toBe(['yolo-prod-my-app-developers']);
});

it('never disturbs a user\'s non-YOLO group memberships', function (): void {
    $changes = PermissionsCommand::membershipChanges(
        offerable: ['yolo-prod-observers'],
        current: ['yolo-prod-observers', 'company-wide-admins'],
        selected: [],
    );

    // Revokes the YOLO grant, leaves the company group entirely alone.
    expect($changes['remove'])->toBe(['yolo-prod-observers']);
    expect($changes['remove'])->not->toContain('company-wide-admins');
});

it('is a no-op when the selection already matches the current YOLO membership', function (): void {
    $changes = PermissionsCommand::membershipChanges(
        offerable: ['yolo-prod-observers', 'yolo-prod-admins'],
        current: ['yolo-prod-observers', 'unrelated'],
        selected: ['yolo-prod-observers'],
    );

    expect($changes['add'])->toBe([]);
    expect($changes['remove'])->toBe([]);
});

it('offers observer, developer and admin grants, env-wide and for this app — never the deployer', function (): void {
    $names = array_column((new PermissionsCommand())->grants(), 'name');

    // The deployer role is CI's (GitHub OIDC); a laptop deploy is an admin act.
    expect($names)->toBe([
        'yolo-testing-observers',
        'yolo-testing-my-app-observers',
        'yolo-testing-developers',
        'yolo-testing-my-app-developers',
        'yolo-testing-admins',
    ]);
});

it('never revokes the account-scoped self-service group, whatever the tier selection', function (): void {
    // yolo-users is deliberately absent from the offerable set, so revoking every
    // tier can't strand a developer mid-rotation.
    $changes = PermissionsCommand::membershipChanges(
        offerable: ['yolo-prod-observers', 'yolo-prod-admins'],
        current: ['yolo-prod-observers', 'yolo-users'],
        selected: [],
    );

    expect($changes['add'])->toBe([]);
    expect($changes['remove'])->toBe(['yolo-prod-observers']);
    expect($changes['remove'])->not->toContain('yolo-users');
});

it('enrols the edited user in yolo-users even when the tier selection is unchanged', function (): void {
    $captured = [];
    bindPermissionsIamClient(
        holds: ['yolo-testing-observers'],
        usersGroupExists: true,
        captured: $captured,
    );

    // Pick the first user, keep the tiers as-is, confirm.
    Prompt::fake([Key::ENTER, Key::ENTER, 'y', Key::ENTER]);

    runPermissionsCommand();

    expect(addedGroups($captured))->toBe(['yolo-users']);
    expect(collect($captured)->pluck('name'))->not->toContain('RemoveUserFromGroup');
});

it('enrols in yolo-users alongside a tier grant, and never enrols twice', function (): void {
    $captured = [];
    bindPermissionsIamClient(
        holds: ['yolo-testing-observers', 'yolo-users'],
        usersGroupExists: true,
        captured: $captured,
    );

    // Nothing to apply, so the command returns before the confirm prompt.
    Prompt::fake([Key::ENTER, Key::ENTER]);

    runPermissionsCommand();

    // Already a member and no tier change — nothing to apply at all.
    expect(collect($captured)->pluck('name'))->not->toContain('AddUserToGroup');
});

it('skips self-service enrolment when the group has not been provisioned yet', function (): void {
    $captured = [];
    bindPermissionsIamClient(
        holds: ['yolo-testing-observers'],
        usersGroupExists: false,
        captured: $captured,
    );

    // Pick the user, space-toggle the highlighted (held) tier off, confirm.
    Prompt::fake([Key::ENTER, Key::SPACE, Key::ENTER, 'y', Key::ENTER]);

    runPermissionsCommand();

    // sync:account hasn't run — the tier edit still applies, self-service waits.
    expect(collect($captured)->where('name', 'RemoveUserFromGroup')->pluck('args.GroupName')->all())
        ->toBe(['yolo-testing-observers']);
    expect(addedGroups($captured))->toBe([]);
});
