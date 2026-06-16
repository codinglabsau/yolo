<?php

use Codinglabs\Yolo\Commands\PermissionsCommand;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        // A manifest repository wins over env/git-origin, so the deployer grant
        // is offered deterministically regardless of where the suite runs.
        'repository' => 'codinglabsau/example',
    ]);
});

it('grants the tier selected but not held, and revokes the held-but-unselected — only within the offerable set', function (): void {
    $offerable = ['yolo-prod-observers', 'yolo-prod-my-app-observers', 'yolo-prod-my-app-deployers', 'yolo-prod-admins'];
    $current = ['yolo-prod-observers', 'yolo-prod-my-app-deployers', 'some-other-team-group'];
    $selected = ['yolo-prod-observers', 'yolo-prod-admins'];

    $changes = PermissionsCommand::membershipChanges($offerable, $current, $selected);

    expect($changes['add'])->toBe(['yolo-prod-admins']);
    expect($changes['remove'])->toBe(['yolo-prod-my-app-deployers']);
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

it('offers env + per-app grants for this app, deployer included when a repository is set', function (): void {
    $names = array_column((new PermissionsCommand())->grants(), 'name');

    expect($names)->toBe([
        'yolo-testing-observers',
        'yolo-testing-my-app-observers',
        'yolo-testing-my-app-deployers',
        'yolo-testing-admins',
    ]);
});
