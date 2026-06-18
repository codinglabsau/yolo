<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;

/**
 * Deregisters every ACTIVE task-definition revision in this app's families (web,
 * plus the standalone queue / scheduler families when extracted). A task-def
 * family is the service name. Run after the services are deleted: live revisions
 * left behind would otherwise surface in `yolo audit` once the cluster is gone.
 */
class DeregisterTaskDefinitionsStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $arns = $this->families()
            ->flatMap(fn (string $family): array => $this->activeRevisions($family))
            ->all();

        if ($arns === []) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('task definitions', sprintf('%d active revision(s)', count($arns)), null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        foreach ($arns as $arn) {
            Aws::ecs()->deregisterTaskDefinition(['taskDefinition' => $arn]);
        }

        return StepResult::DELETED;
    }

    /**
     * @return Collection<int, string>
     */
    protected function families(): Collection
    {
        /** @var list<ServerGroup> $groups */
        $groups = [ServerGroup::WEB];

        if (Manifest::hasStandaloneQueue()) {
            $groups[] = ServerGroup::QUEUE;
        }

        if (Manifest::hasStandaloneScheduler()) {
            $groups[] = ServerGroup::SCHEDULER;
        }

        return collect($groups)->map(fn (ServerGroup $group): string => (new EcsService($group))->name());
    }

    /**
     * @return array<int, string>
     */
    protected function activeRevisions(string $family): array
    {
        $arns = [];
        $token = null;

        do {
            $result = Aws::ecs()->listTaskDefinitions(array_filter([
                'familyPrefix' => $family,
                'status' => 'ACTIVE',
                'nextToken' => $token,
            ]));

            foreach ($result['taskDefinitionArns'] ?? [] as $arn) {
                $arns[] = $arn;
            }

            $token = $result['nextToken'] ?? null;
        } while ($token !== null);

        return $arns;
    }
}
