<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\AttachDeployerRolePoliciesStep;
use Codinglabs\Yolo\Steps\Sync\App\AttachAppObserverRolePolicyStep;
use Codinglabs\Yolo\Steps\Sync\Environment\AttachAdminRolePolicyStep;
use Codinglabs\Yolo\Steps\Sync\App\AttachAppDeveloperRolePoliciesStep;
use Codinglabs\Yolo\Steps\Sync\Environment\AttachObserverRolePolicyStep;
use Codinglabs\Yolo\Steps\Sync\Environment\AttachDeveloperRolePoliciesStep;

/**
 * Which documents each tier role composes. The invariant that matters most:
 * the deployer role — CI's — never carries a data document, whatever the
 * human tiers gain.
 *
 * @return array<int, string> the policy ARNs a step attaches to a bare role
 */
function attachedByStep(object $step): array
{
    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => new Result(['AttachedPolicies' => []]),
    ], $captured);

    expect($step([]))->toBe(StepResult::SYNCED);

    return collect($captured)
        ->where('name', 'AttachRolePolicy')
        ->map(fn (array $call): string => str_replace('arn:aws:iam::111111111111:policy/', '', (string) $call['args']['PolicyArn']))
        ->values()
        ->all();
}

it('composes the env tiers: observer reads data, developer also writes it, admin adds the infrastructure', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(attachedByStep(new AttachObserverRolePolicyStep()))
        ->toBe(['yolo-testing-observer', 'yolo-testing-data-read']);

    expect(attachedByStep(new AttachDeveloperRolePoliciesStep()))
        ->toBe(['yolo-testing-observer', 'yolo-testing-data-read', 'yolo-testing-data-write']);

    expect(attachedByStep(new AttachAdminRolePolicyStep()))
        ->toBe(['yolo-testing-observer', 'yolo-testing-data-read', 'yolo-testing-data-write', 'yolo-testing-admin']);
});

it('composes the per-app tiers the same way, fenced to this app', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true]);

    expect(attachedByStep(new AttachAppObserverRolePolicyStep()))
        ->toBe(['yolo-testing-my-app-observer', 'yolo-testing-my-app-data-read']);

    expect(attachedByStep(new AttachAppDeveloperRolePoliciesStep()))
        ->toBe(['yolo-testing-my-app-observer', 'yolo-testing-my-app-data-read', 'yolo-testing-my-app-data-write']);
});

it('leaves the per-app data-read document out when the app declares no bucket — it would have no resource', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(attachedByStep(new AttachAppObserverRolePolicyStep()))
        ->toBe(['yolo-testing-my-app-observer']);

    // The write document keeps its dump-prefix read, so it always exists.
    expect(attachedByStep(new AttachAppDeveloperRolePoliciesStep()))
        ->toBe(['yolo-testing-my-app-observer', 'yolo-testing-my-app-data-write']);
});

it('never hands the deployer role a data document — a deploy grant can\'t read user uploads', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true, 'repository' => 'my-org/my-repo']);

    $attached = attachedByStep(new AttachDeployerRolePoliciesStep());

    expect($attached)->toBe(['yolo-testing-my-app-deployer-policy', 'yolo-testing-my-app-observer']);
    expect(implode(' ', $attached))->not->toContain('data-');
});
