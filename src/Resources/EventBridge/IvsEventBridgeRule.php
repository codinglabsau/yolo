<?php

namespace Codinglabs\Yolo\Resources\EventBridge;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\EventBridge;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * EventBridge rule that matches IVS state-change events and routes them to the
 * IvsLogGroup (via IvsEventBridgeTargetStep). putRule is an upsert, so rule
 * config is reconciled through synchroniseConfiguration on every existing sync.
 */
class IvsEventBridgeRule implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('ivs-state-change');
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            EventBridge::rule($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return EventBridge::rule($this->name())['Arn'];
    }

    public function create(): void
    {
        $this->putRule();
        $this->synchroniseTags(apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEventBridgeTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Reconcile the rule's event pattern, state and description, read-compared
     * against the live rule so a clean sync makes no write and a dry-run reports
     * the drift. Returns the drifted attributes as Change[].
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $live = EventBridge::rule($this->name());

        $changes = [];

        if (! Helpers::documentsEqual(json_decode($live['EventPattern'] ?? 'null', true), $this->eventPattern())) {
            $changes[] = Change::make('event-pattern', $live['EventPattern'] ?? null, json_encode($this->eventPattern()));
        }

        if (($live['State'] ?? null) !== 'ENABLED') {
            $changes[] = Change::make('state', $live['State'] ?? null, 'ENABLED');
        }

        if (($live['Description'] ?? null) !== $this->description()) {
            $changes[] = Change::make('description', $live['Description'] ?? null, $this->description());
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        $this->putRule();

        return $changes;
    }

    public function eventPattern(): array
    {
        return ['source' => ['aws.ivs']];
    }

    public function description(): string
    {
        return 'YOLO managed IVS state change events';
    }

    protected function putRule(): void
    {
        Aws::eventBridge()->putRule([
            'Name' => $this->name(),
            'Description' => $this->description(),
            'EventPattern' => json_encode($this->eventPattern()),
            'State' => 'ENABLED',
        ]);
    }
}
