<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
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
 * Adjust the web service's running capacity out of band — no build, no task
 * definition revision. Mirrors env:push's compare-then-confirm UX: read live
 * state, show a current → new table, gate on a confirm, and bail with the chick.
 *
 * Autoscaling-aware. With no scalable target registered it sets the ECS service's
 * desired count directly. Once autoscaling is live, a raw desired count is just
 * overridden on the next evaluation, so scale instead raises the target's minimum
 * capacity — the floor — and renders desired count as autoscaling-managed.
 */
class ScaleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('scale')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addArgument('count', InputArgument::OPTIONAL, 'The desired number of tasks (prompts when omitted)')
            ->setDescription('Scale the web service out of band, without a build or deploy');
    }

    public function handle(): void
    {
        $cluster = (new EcsCluster())->name();
        $serviceName = (new EcsService())->name();

        try {
            $service = Ecs::service($cluster, $serviceName);
        } catch (ResourceDoesNotExistException) {
            error(sprintf('Could not find the web service for %s — has it been deployed?', $this->argument('environment')));

            return;
        }

        $currentDesired = (int) $service['desiredCount'];
        $running = (int) $service['runningCount'];

        $target = new ScalableTarget();
        $live = $target->current();
        $managed = $live !== null;

        $new = $this->resolveCount($managed ? $live['min'] : $currentDesired);

        note('Comparing changes...');

        table(['Field', 'Current', 'New'], static::rows($managed, $currentDesired, $running, $live, $new));

        $unchanged = $managed ? $new === $live['min'] : $new === $currentDesired;

        $confirmed = $unchanged
            ? confirm('No change detected - do you want to scale anyway?')
            : confirm(sprintf('Are you sure you want to scale %s to %d %s?', $this->argument('environment'), $new, Str::plural('task', $new)));

        if (! $confirmed) {
            info('🐥 yolo');

            return;
        }

        if ($managed) {
            note(sprintf('Raising the autoscaling minimum to %d...', $new));

            // Keep the ceiling — never let raising the floor lower the max below it.
            $target->register($new, max($new, $live['max']));
        } else {
            note(sprintf('Scaling to %d...', $new));

            Aws::ecs()->updateService([
                'cluster' => $cluster,
                'service' => $serviceName,
                'desiredCount' => $new,
            ]);
        }

        info('Scaled successfully.');
    }

    /**
     * The comparison rows for the confirm table. When autoscaling manages the
     * service the changing field is the target's minimum capacity (the floor);
     * desired count is shown as autoscaling-managed since setting it directly
     * would be overridden on the next evaluation.
     *
     * @param  array{min: int, max: int}|null  $live
     * @return array<int, array<int, string>>
     */
    public static function rows(bool $managed, int $currentDesired, int $running, ?array $live, int $new): array
    {
        if ($managed) {
            return [
                ['Min capacity', (string) $live['min'], (string) $new],
                ['Desired count', '— (autoscaling-managed)', '—'],
                ['Running', (string) $running, '—'],
            ];
        }

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
