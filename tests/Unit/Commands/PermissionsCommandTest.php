<?php

use Codinglabs\Yolo\Commands\PermissionsCommand;

beforeEach(function (): void {
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
