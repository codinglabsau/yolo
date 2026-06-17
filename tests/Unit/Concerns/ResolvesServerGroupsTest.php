<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\ResolvesServerGroups;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

function serverGroupResolver(): object
{
    return new class()
    {
        use ResolvesServerGroups;

        /**
         * @return array<int, ServerGroup>
         */
        public function resolve(?string $only): array
        {
            return $this->resolveServerGroups($only);
        }
    };
}

beforeEach(function (): void {
    // A web-only app: serverGroups() resolves to [WEB] (no standalone queue/scheduler).
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('returns every group the app runs when no filter is given', function (): void {
    expect(serverGroupResolver()->resolve(null))->toBe([ServerGroup::WEB]);
});

it('resolves a valid --group token to its enum case', function (): void {
    expect(serverGroupResolver()->resolve('web'))->toBe([ServerGroup::WEB]);
});

it('rejects an unknown --group token', function (): void {
    serverGroupResolver()->resolve('bogus');
})->throws(IntegrityCheckException::class, 'Unknown --group "bogus"');

it('rejects a known group the app does not run as its own service', function (): void {
    // web-only app has no standalone queue service, so `queue` is not available
    serverGroupResolver()->resolve('queue');
})->throws(IntegrityCheckException::class);
