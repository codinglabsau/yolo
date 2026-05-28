<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;

/**
 * A minimal Resource using the trait at a given scope — so we can assert that
 * scope() (not a hand-written tag) is what drives the yolo:app owner tag.
 */
function fakeResource(Scope $scope, string $name): Resource
{
    return new class($scope, $name) implements Resource
    {
        use ResolvesTags;

        public function __construct(private Scope $scope, private string $resourceName) {}

        public function name(): string
        {
            return $this->resourceName;
        }

        public function scope(): Scope
        {
            return $this->scope;
        }

        public function exists(): bool
        {
            return true;
        }

        public function arn(): string
        {
            return 'arn:aws:fake';
        }

        public function create(): void {}

        public function synchroniseTags(bool $apply): array
        {
            return [];
        }
    };
}

it('stamps yolo:scope and yolo:app on an app-scoped resource', function () {
    expect(fakeResource(Scope::App, 'yolo-testing-my-app-thing')->tags())->toBe([
        'Name' => 'yolo-testing-my-app-thing',
        'yolo:scope' => 'app',
        'yolo:app' => 'my-app',
    ]);
});

it('stamps yolo:scope and omits yolo:app on env- and account-scoped resources', function (Scope $scope, string $expectedScope) {
    expect(fakeResource($scope, 'yolo-testing-shared-thing')->tags())->toBe([
        'Name' => 'yolo-testing-shared-thing',
        'yolo:scope' => $expectedScope,
    ]);
})->with([
    'env' => [Scope::Env, 'env'],
    'account' => [Scope::Account, 'account'],
]);
