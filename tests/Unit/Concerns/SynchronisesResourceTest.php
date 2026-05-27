<?php

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/** A Resource whose existence and config drift the test controls. */
class FakeConfigResource implements Resource, SynchronisesConfiguration
{
    public ?bool $appliedWith = null;

    public bool $tagsSynced = false;

    public bool $created = false;

    /** @param  array<int, Change>  $changes */
    public function __construct(public bool $present, public array $changes = []) {}

    public function name(): string
    {
        return 'fake';
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function tags(): array
    {
        return [];
    }

    public function exists(): bool
    {
        return $this->present;
    }

    public function arn(): string
    {
        return 'arn:fake';
    }

    public function create(): void
    {
        $this->created = true;
    }

    public function synchroniseTags(): void
    {
        $this->tagsSynced = true;
    }

    public function synchroniseConfiguration(bool $apply = true): array
    {
        $this->appliedWith = $apply;

        return $this->changes;
    }
}

class SyncFakeResourceStep implements Step
{
    use SynchronisesResource;

    public function __construct(public Resource $resource) {}

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource($this->resource, $options);
    }
}

it('reports a clean existing resource as SYNCED with no recorded changes', function () {
    $resource = new FakeConfigResource(present: true, changes: []);
    $step = new SyncFakeResourceStep($resource);

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBe([]);
    expect($resource->tagsSynced)->toBeTrue();
    expect($resource->appliedWith)->toBeTrue();
});

it('reports SYNCED and records the changes a real sync applied to a drifted resource', function () {
    $change = Change::make('flag', false, true);
    $resource = new FakeConfigResource(present: true, changes: [$change]);
    $step = new SyncFakeResourceStep($resource);

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBe([$change]);
    expect($resource->appliedWith)->toBeTrue();
});

it('reports WOULD_SYNC and records changes without applying them on a dry-run', function () {
    $change = Change::make('flag', false, true);
    $resource = new FakeConfigResource(present: true, changes: [$change]);
    $step = new SyncFakeResourceStep($resource);

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->toBe([$change]);
    // Diff computed (apply=false) but neither config nor tags were written.
    expect($resource->appliedWith)->toBeFalse();
    expect($resource->tagsSynced)->toBeFalse();
});

it('would-create an absent resource on a dry-run and creates it for real', function () {
    expect((new SyncFakeResourceStep(new FakeConfigResource(present: false)))(['dry-run' => true]))
        ->toBe(StepResult::WOULD_CREATE);

    $resource = new FakeConfigResource(present: false);
    expect((new SyncFakeResourceStep($resource))([]))->toBe(StepResult::CREATED);
    expect($resource->created)->toBeTrue();
});
