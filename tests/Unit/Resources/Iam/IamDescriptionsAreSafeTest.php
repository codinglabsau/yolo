<?php

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\DeveloperRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Resources\Iam\DataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;
use Codinglabs\Yolo\Resources\Iam\AppDeveloperRole;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;
use Codinglabs\Yolo\Resources\Iam\AppDataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\AppDataWritePolicy;

/**
 * IAM `Description` fields (CreateRole, UpdateRole, CreatePolicy) accept only
 * tab/LF/CR + printable ASCII (U+0020–U+007E) + Latin-1 Supplement (U+00A1–U+00FF).
 * Em dashes, smart quotes, and the C1 control range (U+007F–U+00A0) are rejected
 * with `ValidationError` at the API.
 *
 * Caught in the wild: a stray em dash in EcsTaskRole::description() killed a
 * green-field sync on a fresh account at sync:iam. This test guards every YOLO-authored IAM
 * description against the same class of bug.
 */
const IAM_DESCRIPTION_PATTERN = '/^[\x{0009}\x{000A}\x{000D}\x{0020}-\x{007E}\x{00A1}-\x{00FF}]*$/u';

it('EcsTaskRole description is safe for the IAM API', function (): void {
    expect((new EcsTaskRole())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('EcsTaskPolicy description is safe for the IAM API', function (): void {
    expect((new EcsTaskPolicy())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('EcsExecutionRole description is safe for the IAM API', function (): void {
    expect((new EcsExecutionRole())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('MediaConvertRole description is safe for the IAM API', function (): void {
    expect((new MediaConvertRole())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('DeployerRole description is safe for the IAM API', function (): void {
    expect((new DeployerRole())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('DeployerPolicy description is safe for the IAM API', function (): void {
    expect((new DeployerPolicy())->description())->toMatch(IAM_DESCRIPTION_PATTERN);
});

it('keeps every data-tier description safe for the IAM API', function (object $resource): void {
    expect($resource->description())->toMatch(IAM_DESCRIPTION_PATTERN);
})->with([
    'DataReadPolicy' => [fn (): DataReadPolicy => new DataReadPolicy()],
    'AppDataReadPolicy' => [fn (): AppDataReadPolicy => new AppDataReadPolicy()],
    'DataWritePolicy' => [fn (): DataWritePolicy => new DataWritePolicy()],
    'AppDataWritePolicy' => [fn (): AppDataWritePolicy => new AppDataWritePolicy()],
    'DeveloperRole' => [fn (): DeveloperRole => new DeveloperRole()],
    'AppDeveloperRole' => [fn (): AppDeveloperRole => new AppDeveloperRole()],
]);

it('rejects an em dash so the regex actually catches the original bug', function (): void {
    expect('YOLO managed ECS task role — shared default')->not->toMatch(IAM_DESCRIPTION_PATTERN);
});
