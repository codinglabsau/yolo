<?php

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Adoptable;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
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
    // The bug the new shape fixes: previously, a resource with tag drift
    // returned a clean SYNCED at plan time (synchroniseTags was a no-op void),
    // so the "only-pending-steps" filter dropped it from apply, so the tag
    // never got written. Now tag drift is recorded as a Change and surfaces as
    // WOULD_SYNC (dry-run) / SYNCED-with-changes (real).
    $resource = new FakeConfigResource(present: true, missingTags: ['yolo:app' => 'my-app']);
    $step = new SyncFakeResourceStep($resource);

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->toHaveCount(1);
    expect($step->changes()[0]->attribute)->toBe('tag yolo:app');
    expect($step->changes()[0]->to)->toBe('my-app');
    expect($resource->tagsAppliedWith)->toBeFalse();
});

it('refuses to adopt an existing resource whose live tags are missing the yolo:scope marker', function (): void {
    // A name-match with no ownership marker is a stranger — most dangerously
    // another deployment tool's live resource sharing the account. Stamping
    // YOLO tags on it would claim infrastructure that isn't ours, so both
    // passes must fail loudly instead (the plan pass surfaces this before the
    // confirm gate, so the apply never runs).
    $resource = new FakeConfigResource(present: true, missingTags: ['yolo:scope' => 'app']);
    $step = new SyncFakeResourceStep($resource);

    expect(fn (): StepResult => $step(['dry-run' => true]))
        ->toThrow(IntegrityCheckException::class, 'Refusing to adopt "fake"');
    expect($resource->tagsAppliedWith)->toBeFalse();

    expect(fn (): StepResult => $step([]))->toThrow(IntegrityCheckException::class);
});

it('adopts a pre-existing resource that opts in via the Adoptable marker', function (): void {
    // The hosted zone / OIDC provider case: a singleton that legitimately
    // pre-exists without YOLO tags is stamped, not refused.
    $resource = new FakeAdoptableResource(present: true, missingTags: ['yolo:scope' => 'app']);
    $step = new SyncFakeResourceStep($resource);

    expect($step([]))->toBe(StepResult::SYNCED);
    expect($resource->tagsAppliedWith)->toBeTrue();
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

class FakeAdoptableResource extends FakeConfigResource implements Adoptable {}

class SyncFakeResourceStep implements Step
{
    use SynchronisesResource;

    public function __construct(public Resource $resource) {}

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource($this->resource, $options);
    }
}
