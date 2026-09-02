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
 * WAFv2 refuses any log destination not prefixed `aws-waf-logs-`, so this is the
 * one YOLO log group that can't lead with the `yolo-{env}-…` convention. WAF writes
 * the log-delivery resource policy onto the group itself when logging is enabled,
 * so unlike the IVS pipeline there is no resource policy to reconcile.
 */
class WafLogGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return 'aws-waf-logs-' . $this->keyedName();
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

    public function synchroniseConfiguration(bool $apply = true): array
    {
        return $this->reconcileRetention($apply);
    }

    /**
     * Long enough that a blocked-request pattern is still on hand weeks later; the
     * IPSet-sync consumer only reads the recent window.
     */
    public function retentionDays(): int
    {
        return 30;
    }

    /**
     * @return array<int, Change>
     */
    protected function reconcileRetention(bool $apply): array
    {
        $logGroup = CloudWatchLogs::logGroup($this->name());
        $current = $logGroup['retentionInDays'] ?? null;

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
