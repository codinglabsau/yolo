<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is an env-scoped role named yolo-{env}-observer-role (shared by the environment)', function (): void {
    expect((new ObserverRole())->scope())->toBe(Scope::Env);
    expect((new ObserverRole())->name())->toBe('yolo-testing-observer-role');
});

it('trusts the account principal, so an identity granted sts:AssumeRole can assume it', function (): void {
    $statement = (new ObserverRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);

    // It's same-account role assumption — never the GitHub OIDC web-identity trust
    // the deployer role uses.
    expect($statement['Action'])->not->toBe('sts:AssumeRoleWithWebIdentity');
});

it('needs no MFA to assume, so a key-only identity can hold read access', function (ObserverRole $role): void {
    expect($role->assumeRolePolicyDocument()['Statement'][0])->not->toHaveKey('Condition');
})->with([
    'env' => [fn (): ObserverRole => new ObserverRole()],
    'app' => [fn (): ObserverRole => new AppObserverRole()],
]);

it('keeps MFA on every role that inherits the observer trust but grants more than reads', function (): void {
    $subclasses = collect(glob(dirname(__DIR__, 4) . '/src/Resources/Iam/*.php'))
        ->map(fn (string $path): string => 'Codinglabs\\Yolo\\Resources\\Iam\\' . basename($path, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, ObserverRole::class))
        ->reject(fn (string $class): bool => $class === AppObserverRole::class);

    expect($subclasses)->not->toBeEmpty();

    foreach ($subclasses as $class) {
        expect((new $class())->assumeRolePolicyDocument()['Statement'][0]['Condition'] ?? null)
            ->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']], $class . ' must require MFA');
    }
});
