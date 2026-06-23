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
        // Keyed by family (the service name) so each family's revision count is
        // reported on its own line — "which task definitions, how many" — rather
        // than a single opaque total across every family.
        $revisionsByFamily = $this->families()
            ->mapWithKeys(fn (string $family): array => [$family => $this->activeRevisions($family)])
            ->filter(fn (array $arns): bool => $arns !== []);

        if ($revisionsByFamily->isEmpty()) {
            return StepResult::SKIPPED;
        }

        foreach ($revisionsByFamily as $family => $arns) {
            $this->recordChange(Change::make(
                sprintf('%s task definitions', $family),
                sprintf('%d active revision(s)', count($arns)),
                null,
            ));
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        foreach ($revisionsByFamily->flatten() as $arn) {
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
