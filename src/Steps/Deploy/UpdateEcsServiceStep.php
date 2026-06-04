<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Concerns\ResolvesServerGroups;

class UpdateEcsServiceStep implements Step
{
    use ResolvesServerGroups;

    public function __construct(protected string $environment) {}

    /**
     * Roll each targeted service group (every group the app runs, or the --group
     * subset) onto the revision RegisterTaskDefinitionRevisionStep just minted.
     * Each service's task-definition family is its own name (see EcsService), so
     * pointing the service at its family adopts that group's newest revision.
     */
    public function __invoke(array $options): StepResult
    {
        $cluster = (new EcsCluster())->name();

        foreach ($this->resolveServerGroups(Arr::get($options, 'group')) as $group) {
            $service = new EcsService($group);

            Aws::ecs()->updateService([
                'cluster' => $cluster,
                'service' => $service->name(),
                'taskDefinition' => $service->name(),
                'forceNewDeployment' => true,
            ]);
        }

        return StepResult::SYNCED;
    }
}
