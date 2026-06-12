<?php

namespace Codinglabs\Yolo\Resources\CloudWatchLogs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Log group for the environment's Typesense nodes — one group, per-node
 * stream prefixes. Retention is hardcoded: node logs are operational
 * telemetry (Raft elections, slow queries), not a source of truth.
 */
class TypesenseLogGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('typesense');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            CloudWatchLogs::logGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return sprintf(
            'arn:aws:logs:%s:%s:log-group:%s',
            Manifest::get('region'),
            Aws::accountId(),
            $this->name(),
        );
    }

    public function create(): void
    {
        Aws::cloudWatchLogs()->createLogGroup([
            'logGroupName' => $this->name(),
            ...Aws::tags($this->tags(), wrap: 'tags', associative: true),
        ]);

        $this->synchroniseConfiguration();
    }

    public function delete(): void
    {
        Aws::cloudWatchLogs()->deleteLogGroup([
            'logGroupName' => $this->name(),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchLogsTags($this->arn(), $this->tags(), $apply);
    }

    public function retentionDays(): int
    {
        return 14;
    }

    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $current = CloudWatchLogs::logGroup($this->name())['retentionInDays'] ?? null;

        if ($current === $this->retentionDays()) {
            return [];
        }

        if ($apply) {
            Aws::cloudWatchLogs()->putRetentionPolicy([
                'logGroupName' => $this->name(),
                'retentionInDays' => $this->retentionDays(),
            ]);
        }

        return [Change::make('retention-days', $current, $this->retentionDays())];
    }
}
