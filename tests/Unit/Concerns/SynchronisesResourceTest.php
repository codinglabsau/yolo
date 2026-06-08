<?php

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/** A Resource whose existence + tag drift + config drift the test controls. */
class FakeConfigResource implements Resource, SynchronisesConfiguration
{
    public ?bool $tagsAppliedWith = null;

    public ?bool $configAppliedWith = null;

    public bool $created = false;

    /**
     * @param  array<string, string>  $missingTags  the tag delta synchroniseTags will report
     * @param  array<int, Change>  $configChanges  the config drift synchroniseConfiguration will report
     */
    public function __construct(
        public bool $present,
        public array $missingTags = [],
        public array $configChanges = [],
    ) {}

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

    public function synchroniseTags(bool $apply): array
    {
        $this->tagsAppliedWith = $apply;

        return $this->missingTags;
    }

    public function synchroniseConfiguration(bool $apply = true): array
    {
        $this->configAppliedWith = $apply;

        return $this->configChanges;
    }
}

it('reports a clean existing resource as SYNCED with no recorded changes', function (): void {
    $resource = new FakeConfigResource(present: true);
    $step = new SyncFakeResourceStep($resource);

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBe([]);
    expect($resource->tagsAppliedWith)->toBeTrue();
    expect($resource->configAppliedWith)->toBeTrue();
});

it('reports SYNCED and records the changes a real sync applied to a config-drifted resource', function (): void {
    $change = Change::make('flag', false, true);
    $resource = new FakeConfigResource(present: true, configChanges: [$change]);
    $step = new SyncFakeResourceStep($resource);

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBe([$change]);
    expect($resource->configAppliedWith)->toBeTrue();
});

it('records missing tags as Changes so tag drift survives the apply-pending filter', function (): void {
    // The bug the new shape fixes: previously, a resource missing yolo:scope=env
    // returned a clean SYNCED at plan time (synchroniseTags was a no-op void),
    // so PR #57's "only-pending-steps" filter dropped it from apply, so the tag
    // never got written. Now tag drift is recorded as a Change and surfaces as
    // WOULD_SYNC (dry-run) / SYNCED-with-changes (real).
    $resource = new FakeConfigResource(present: true, missingTags: ['yolo:scope' => 'env']);
    $step = new SyncFakeResourceStep($resource);

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->toHaveCount(1);
    expect($step->changes()[0]->attribute)->toBe('tag yolo:scope');
    expect($step->changes()[0]->to)->toBe('env');
    expect($resource->tagsAppliedWith)->toBeFalse();
});

it('reports WOULD_SYNC and records changes without applying them on a dry-run', function (): void {
    $change = Change::make('flag', false, true);
    $resource = new FakeConfigResource(present: true, configChanges: [$change]);
    $step = new SyncFakeResourceStep($resource);

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->toBe([$change]);
    // Diff computed (apply=false) but neither config nor tags were written.
    expect($resource->configAppliedWith)->toBeFalse();
    expect($resource->tagsAppliedWith)->toBeFalse();
});

it('would-create an absent resource on a dry-run and creates it for real', function (): void {
    expect((new SyncFakeResourceStep(new FakeConfigResource(present: false)))(['dry-run' => true]))
        ->toBe(StepResult::WOULD_CREATE);

    $resource = new FakeConfigResource(present: false);
    expect((new SyncFakeResourceStep($resource))([]))->toBe(StepResult::CREATED);
    expect($resource->created)->toBeTrue();
});

class SyncFakeResourceStep implements Step
{
    use SynchronisesResource;

    public function __construct(public Resource $resource) {}

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource($this->resource, $options);
    }
}
