<?php

namespace Codinglabs\Yolo\Resources\EventBridge;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Aws\EventBridge;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\AppScoped;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * EventBridge rule that matches IVS state-change events and routes them to the
 * IvsLogGroup (via IvsEventBridgeTargetStep). putRule is an upsert, so rule
 * config is reconciled through synchroniseConfiguration on every existing sync.
 */
class IvsEventBridgeRule implements AppScoped, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return Helpers::keyedResourceName('ivs-state-change');
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
        $this->synchroniseTags();
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEventBridgeTags($this->arn(), $this->tags());
    }

    public function synchroniseConfiguration(): void
    {
        $this->putRule();
    }

    public function eventPattern(): array
    {
        return ['source' => ['aws.ivs']];
    }

    protected function putRule(): void
    {
        Aws::eventBridge()->putRule([
            'Name' => $this->name(),
            'Description' => 'YOLO managed IVS state change events',
            'EventPattern' => json_encode($this->eventPattern()),
            'State' => 'ENABLED',
        ]);
    }
}
