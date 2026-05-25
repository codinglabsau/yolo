<?php

use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\AppScoped;
use Codinglabs\Yolo\Resources\ResolvesTags;

/**
 * A minimal Resource using the trait, optionally marked AppScoped — so we can
 * assert the marker (not a hand-written tag) is what drives yolo:app.
 */
function fakeAppResource(): Resource
{
    return new class() implements AppScoped, Resource
    {
        use ResolvesTags;

        public function name(): string
        {
            return 'yolo-testing-my-app-thing';
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

        public function synchroniseTags(): void {}
    };
}

function fakeSharedResource(): Resource
{
    return new class() implements Resource
    {
        use ResolvesTags;

        public function name(): string
        {
            return 'yolo-testing-shared-thing';
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

        public function synchroniseTags(): void {}
    };
}

it('stamps yolo:app on a resource marked AppScoped', function () {
    expect(fakeAppResource()->tags())->toBe([
        'Name' => 'yolo-testing-my-app-thing',
        'yolo:app' => 'my-app',
    ]);
});

it('omits yolo:app on a resource that is not AppScoped', function () {
    expect(fakeSharedResource()->tags())->toBe([
        'Name' => 'yolo-testing-shared-thing',
    ]);
});
