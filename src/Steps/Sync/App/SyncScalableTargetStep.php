<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

use function Laravel\Prompts\warning;

/**
 * Registers and reconciles the web service's Application Auto Scaling scalable
 * target — the min/max desired-count bounds the policies move within. The
 * manifest is the source of truth (tasks.web.autoscaling.min/max), reconciled
 * to live on every sync. Wired into sync:app whenever the web task exists (not
 * only when autoscaling is on) so removing the block can tear the target down;
 * with no block and nothing registered it no-ops, leaving a fixed app's single
 * create-only task untouched.
 *
 * Reductions are surfaced as Changes and gated by the normal plan → confirm flow,
 * EXCEPT under --force / non-interactive: there the step refuses to lower a live
 * bound (skips + warns) so a stale manifest can never quietly scale production
 * down. Lowering capacity must be a deliberate, attended act — an interactive
 * `yolo sync` (the operator sees the reduction in the plan and confirms) or
 * `yolo scale`. Raises always apply.
 *
 * When the autoscaling block is removed from the manifest the step deregisters
 * the scalable target — Application Auto Scaling cascades the delete to every
 * scaling policy on it and the alarms those policies generated. The ECS service
 * reverts to a fixed task count, frozen at its current live count (deregister
 * doesn't drop tasks); lower it with `yolo scale` if needed.
 *
 * Skips on a greenfield first sync when the ECS service doesn't exist yet.
 */
class SyncScalableTargetStep implements Step
{
    use RecordsChanges;

    /**
     * The workload group this step scales — web here; SyncQueueScalableTargetStep
     * overrides it for the queue.
     */
    protected function group(): ServerGroup
    {
        return ServerGroup::WEB;
    }

    public function __invoke(array $options): StepResult
    {
        if (! (new EcsService($this->group()))->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $target = new ScalableTarget($this->group());
        $live = $target->current();

        if (! Manifest::hasAutoscaling()) {
            if ($live === null) {
                return StepResult::SKIPPED;
            }

            $this->recordChanges([Change::make('web autoscaling', sprintf('%d-%d', $live['min'], $live['max']), null)]);

            if (! $dryRun) {
                $target->deregister();
            }

            return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
        }

        if (! $dryRun && static::wouldReduce($target, $live) && static::unattended($options)) {
            warning(sprintf(
                'Skipped the %s autoscaling reduction: manifest bounds (%d–%d) are below live (%d–%d). Lower capacity with an interactive `yolo sync` or `yolo scale` — never unattended.',
                $this->group()->value,
                $target->min(),
                $target->max(),
                $live['min'],
                $live['max'],
            ));

            return StepResult::SKIPPED;
        }

        $changes = $target->synchronise(apply: ! $dryRun);

        $this->recordChanges($changes);

        if ($live === null) {
            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
        }

        if ($changes !== []) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
    }

    /**
     * Would reconciling the manifest bounds lower either live bound?
     *
     * @param  array{min: int, max: int}|null  $live
     */
    protected static function wouldReduce(ScalableTarget $target, ?array $live): bool
    {
        return $live !== null && ($target->min() < $live['min'] || $target->max() < $live['max']);
    }

    /**
     * An unattended run is one with no human at the confirm gate — `--force` or a
     * non-interactive terminal. (Input may be unbound in unit tests, which is
     * treated as interactive so only an explicit --force trips the guard there.)
     *
     * @param  array<string, mixed>  $options
     */
    protected static function unattended(array $options): bool
    {
        if (Arr::get($options, 'force')) {
            return true;
        }

        return Helpers::app()->bound('input') && ! Helpers::app('input')->isInteractive();
    }
}
