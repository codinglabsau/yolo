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
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

/**
 * Wired into sync:app whenever the web task exists (not only when autoscaling
 * is on) so removing the block can tear the target down. Deregistering cascades
 * to every policy and alarm; the ECS service freezes at its current live count
 * (deregister doesn't drop tasks) — lower it with `yolo scale`.
 *
 * Reductions are gated by the plan → confirm flow, EXCEPT under --force /
 * non-interactive: there the step refuses to lower a live bound so a stale
 * manifest can never quietly scale production down. Raises always apply.
 *
 * Never gates on the ECS service existing: it doesn't on a greenfield plan
 * pass, and a bare SKIPPED would prune the registration from apply.
 */
class SyncScalableTargetStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    protected function group(): ServerGroup
    {
        return ServerGroup::WEB;
    }

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');
        $target = new ScalableTarget($this->group());
        $live = $target->current();

        if (! Manifest::autoscales($this->group())) {
            if ($live === null) {
                return StepResult::SKIPPED;
            }

            $this->recordChanges([Change::make(
                sprintf('%s autoscaling', (new EcsService($this->group()))->name()),
                sprintf('min %d / max %d', $live['min'], $live['max']),
                null,
            )]);

            if (! $dryRun) {
                $target->deregister();
            }

            return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
        }

        if (! $dryRun && static::wouldReduce($target, $live) && static::unattended($options)) {
            $this->recordWarning(sprintf(
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
     * @param  array{min: int, max: int}|null  $live
     */
    protected static function wouldReduce(ScalableTarget $target, ?array $live): bool
    {
        return $live !== null && ($target->min() < $live['min'] || $target->max() < $live['max']);
    }

    /**
     * Input may be unbound in unit tests — treated as interactive so only an
     * explicit --force trips the guard there.
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
