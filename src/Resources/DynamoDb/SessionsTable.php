<?php

namespace Codinglabs\Yolo\Resources\DynamoDb;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\DynamoDb;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Enums\DynamoDb as DynamoDbEnum;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Per-app DynamoDB table backing the `dynamodb` session driver. Laravel's
 * dynamodb session driver is cache-backed, so the schema is the Laravel cache
 * table: a string `key` partition key with a `expires_at` TTL attribute.
 * On-demand billing (no capacity to manage) and multi-AZ by default, so it has
 * no single point of failure — the reason a session-sensitive app reaches for
 * it over the single Valkey node.
 */
class SessionsTable implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(DynamoDbEnum::SESSIONS_TABLE);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            DynamoDb::table($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return DynamoDb::table($this->name())['TableArn'];
    }

    public function create(): void
    {
        Aws::dynamoDb()->createTable([
            'TableName' => $this->name(),
            'AttributeDefinitions' => [
                ['AttributeName' => 'key', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'key', 'KeyType' => 'HASH'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
            ...Aws::tags($this->tags()),
        ]);

        Aws::waitFor(Aws::dynamoDb(), 'TableExists', [
            'TableName' => $this->name(),
        ], timeout: 5 * 60, interval: 10);

        Aws::dynamoDb()->updateTimeToLive([
            'TableName' => $this->name(),
            'TimeToLiveSpecification' => [
                'AttributeName' => 'expires_at',
                'Enabled' => true,
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseDynamoDbTags($this->arn(), $this->tags(), $apply);
    }
}
