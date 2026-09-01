<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\Iam\IamClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Account\SyncServiceLinkedRolesStep;

/**
 * A path-aware IAM mock: ListRoles resolves by its PathPrefix (present when the
 * prefix names one of $existing's services, empty otherwise), and
 * CreateServiceLinkedRole resolves from $createResults by service name (a
 * Throwable rejects — the shared bindRoutedIamClient can't reject, and can't
 * tell the per-service ListRoles calls apart).
 *
 * @param  array<int, string>  $existing  service names whose SLR already exists
 * @param  array<string, Result|Throwable>  $createResults
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindServiceLinkedRoleMock(array $existing, array $createResults, array &$captured): void
{
    $mock = new class($existing, $createResults, $captured) extends MockHandler
    {
        /**
         * @param  array<int, string>  $existing
         * @param  array<string, Result|Throwable>  $createResults
         * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
         */
        public function __construct(
            protected array $existing,
            protected array $createResults,
            protected array &$captured,
        ) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $args = $cmd->toArray();
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $args];

            if ($cmd->getName() === 'ListRoles') {
                $found = collect($this->existing)
                    ->contains(fn (string $service): bool => ($args['PathPrefix'] ?? '') === "/aws-service-role/{$service}/");

                return Create::promiseFor(new Result([
                    'Roles' => $found ? [['RoleName' => 'AWSServiceRoleForSomething']] : [],
                ]));
            }

            $entry = $this->createResults[$args['AWSServiceName'] ?? ''] ?? new Result();

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('iam', new IamClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function slrException(string $code, string $message): IamException
{
    return new IamException($message, new Command('CreateServiceLinkedRole'), [
        'code' => $code,
        'message' => $message,
    ]);
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('reports in-sync when every service-linked role already exists', function (): void {
    $captured = [];
    bindServiceLinkedRoleMock(SyncServiceLinkedRolesStep::SERVICES, [], $captured);

    $step = new SyncServiceLinkedRolesStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::SYNCED)
        ->and($step->changes())->toBe([])
        ->and(collect($captured)->pluck('name')->unique()->all())->toBe(['ListRoles']);
});

it('plans every missing role as a pending create on a greenfield account without writing', function (): void {
    $captured = [];
    bindServiceLinkedRoleMock([], [], $captured);

    $step = new SyncServiceLinkedRolesStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and(collect($step->changes())->pluck('attribute')->all())->toBe(SyncServiceLinkedRolesStep::SERVICES)
        ->and(collect($captured)->pluck('name')->unique()->all())->toBe(['ListRoles']);
});

it('creates only the missing service-linked roles on apply', function (): void {
    $captured = [];
    bindServiceLinkedRoleMock(['ecs.amazonaws.com'], [], $captured);

    $step = new SyncServiceLinkedRolesStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::CREATED);

    $created = collect($captured)
        ->where('name', 'CreateServiceLinkedRole')
        ->pluck('args.AWSServiceName')
        ->all();

    expect($created)->toBe(['ecs.application-autoscaling.amazonaws.com', 'elasticache.amazonaws.com']);
});

it('tolerates losing the creation race to an implicit create', function (): void {
    $captured = [];
    bindServiceLinkedRoleMock([], [
        'ecs.amazonaws.com' => slrException(
            'InvalidInput',
            'Service role name AWSServiceRoleForECS has been taken in this account'
        ),
    ], $captured);

    $step = new SyncServiceLinkedRolesStep();

    // The race loser still creates the remaining roles and reports CREATED.
    expect($step(['dry-run' => false]))->toBe(StepResult::CREATED)
        ->and(collect($captured)->where('name', 'CreateServiceLinkedRole')->count())->toBe(3);
});

it('propagates a genuine create failure', function (): void {
    $captured = [];
    bindServiceLinkedRoleMock([], [
        'ecs.amazonaws.com' => slrException('AccessDenied', 'not authorized to perform iam:CreateServiceLinkedRole'),
    ], $captured);

    (new SyncServiceLinkedRolesStep())(['dry-run' => false]);
})->throws(IamException::class);
