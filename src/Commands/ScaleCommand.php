<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;

/**
 * Adjust a service's capacity out of band — no build, no task-definition
 * revision. Mirrors env:push's compare-then-confirm UX: read live state, show a
 * current → new table, gate on a confirm, bail with the chick.
 *
 * The manifest is the source of truth, so the autoscaled path writes the new
 * bounds back to yolo.yml (surgically, preserving formatting) and registers them
 * — sync then reconciles to the same values rather than clobbering them. The
 * fixed path (a web service with no scalable target) sets the ECS desired count
 * directly, since there are no bounds to manage.
 *
 *   yolo scale production --web --min=3 --max=10     # web autoscaling bounds
 *   yolo scale production --web 3                     # fixed web desired count
 *   yolo scale production --queue --min=0 --max=20    # queue bounds (min 0 = scale to zero)
 *   yolo scale production --scheduler …               # error — the scheduler is a singleton
 */
class ScaleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('scale')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addArgument('count', InputArgument::OPTIONAL, 'Desired task count for a fixed (non-autoscaled) service')
            ->addOption('web', null, InputOption::VALUE_NONE, 'Scale the web service (default)')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Scale the queue service')
            ->addOption('scheduler', null, InputOption::VALUE_NONE, 'Scale the scheduler (not permitted)')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Autoscaling minimum capacity')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Autoscaling maximum capacity')
            ->setDescription('Scale a service out of band, without a build or deploy');
    }

    public function handle(): void
    {
        if (($group = $this->resolveGroup()) === null) {
            return;
        }

        $cluster = (new EcsCluster())->name();
        $serviceName = (new EcsService($group))->name();

        try {
            $service = Ecs::service($cluster, $serviceName);
        } catch (ResourceDoesNotExistException) {
            error(sprintf('Could not find the %s service for %s — has it been deployed?', $group->value, $this->argument('environment')));

            return;
        }

        $target = new ScalableTarget($group);
        $live = $target->current();

        if ($this->option('min') !== null || $this->option('max') !== null) {
            $this->scaleBounds($group, $target, $live);

            return;
        }

        // A standalone queue is always autoscaling-managed, as is any web service
        // with a registered target. Setting a fixed desired count there is futile
        // (the policies override it), so redirect to the bounds form rather than
        // quietly no-op. Only a fixed web service falls through to desired count.
        if ($group === ServerGroup::QUEUE || $live !== null) {
            error('This service is autoscaling-managed — use --min/--max to change its bounds, not a desired count.');

            return;
        }

        $this->scaleDesiredCount($cluster, $serviceName, (int) $service['desiredCount'], (int) $service['runningCount']);
    }

    /**
     * Resolve the target group from the flags. The scheduler is a singleton and
     * can never be scaled; web is the default. Returns null when the command
     * should stop (error already surfaced).
     */
    protected function resolveGroup(): ?ServerGroup
    {
        if ($this->option('scheduler')) {
            error('The scheduler is a singleton and cannot be scaled — it always runs exactly one task.');

            return null;
        }

        return $this->option('queue') ? ServerGroup::QUEUE : ServerGroup::WEB;
    }

    protected function scaleBounds(ServerGroup $group, ScalableTarget $target, ?array $live): void
    {
        $newMin = $this->option('min') !== null ? (int) $this->option('min') : ($live['min'] ?? $target->min());
        $newMax = $this->option('max') !== null ? (int) $this->option('max') : ($live['max'] ?? $target->max());

        // The queue may floor at zero (scale to zero); the web tier must keep at
        // least one task serving.
        $floor = $group === ServerGroup::QUEUE ? 0 : 1;

        if ($newMin < $floor) {
            error(sprintf('Minimum capacity for the %s service cannot be below %d.', $group->value, $floor));

            return;
        }

        if ($newMin > $newMax) {
            error(sprintf('Minimum capacity (%d) cannot exceed maximum capacity (%d).', $newMin, $newMax));

            return;
        }

        note('Comparing changes...');

        table(['Field', 'Current', 'New'], static::boundsRows($live, $newMin, $newMax));

        $reducing = $live !== null && ($newMin < $live['min'] || $newMax < $live['max']);

        $confirmed = $reducing
            ? confirm(label: sprintf('This reduces capacity for %s. Reduce anyway?', $this->argument('environment')), default: false)
            : confirm(sprintf('Apply these autoscaling bounds to %s?', $this->argument('environment')));

        if (! $confirmed) {
            info('🐥 Nothing scaled.');

            return;
        }

        // Manifest is the source of truth — write the bounds back (surgically, so
        // comments/formatting survive) so the next sync reconciles to these values
        // rather than clobbering them.
        [$minKey, $maxKey] = static::boundsKeys($group);

        Manifest::put($minKey, $newMin);
        Manifest::put($maxKey, $newMax);

        $target->register($newMin, $newMax);

        info('Scaled successfully.');
    }

    protected function scaleDesiredCount(string $cluster, string $serviceName, int $currentDesired, int $running): void
    {
        $new = $this->resolveCount($currentDesired);

        note('Comparing changes...');

        table(['Field', 'Current', 'New'], static::desiredCountRows($currentDesired, $running, $new));

        $confirmed = $new === $currentDesired
            ? confirm('No change detected - do you want to scale anyway?')
            : confirm(sprintf('Are you sure you want to scale %s to %d %s?', $this->argument('environment'), $new, $new === 1 ? 'task' : 'tasks'));

        if (! $confirmed) {
            info('🐥 Nothing scaled.');

            return;
        }

        note(sprintf('Scaling to %d...', $new));

        Aws::ecs()->updateService([
            'cluster' => $cluster,
            'service' => $serviceName,
            'desiredCount' => $new,
        ]);

        info('Scaled successfully.');
    }

    /**
     * The manifest min/max key paths for a group — web autoscaling bounds live
     * under tasks.web.autoscaling, the queue's directly under tasks.queue (a
     * standalone queue is always autoscaled).
     *
     * @return array{0: string, 1: string}
     */
    public static function boundsKeys(ServerGroup $group): array
    {
        return $group === ServerGroup::QUEUE
            ? ['tasks.queue.min', 'tasks.queue.max']
            : ['tasks.web.autoscaling.min', 'tasks.web.autoscaling.max'];
    }

    /**
     * Bounds comparison rows for the autoscaled path (current → new min/max).
     *
     * @param  array{min: int, max: int}|null  $live
     * @return array<int, array<int, string>>
     */
    public static function boundsRows(?array $live, int $newMin, int $newMax): array
    {
        return [
            ['Min capacity', $live !== null ? (string) $live['min'] : '—', (string) $newMin],
            ['Max capacity', $live !== null ? (string) $live['max'] : '—', (string) $newMax],
            ['Desired count', '— (autoscaling-managed)', '—'],
        ];
    }

    /**
     * Desired-count comparison rows for the fixed (non-autoscaled) path.
     *
     * @return array<int, array<int, string>>
     */
    public static function desiredCountRows(int $currentDesired, int $running, int $new): array
    {
        return [
            ['Desired count', (string) $currentDesired, (string) $new],
            ['Running', (string) $running, '—'],
        ];
    }

    protected function resolveCount(int $default): int
    {
        $count = $this->argument('count');

        if ($count !== null) {
            return (int) $count;
        }

        return (int) text(
            label: 'Desired number of tasks',
            default: (string) $default,
            validate: fn (string $value) => ctype_digit($value) ? null : 'Enter a whole number of tasks.',
        );
    }
}
