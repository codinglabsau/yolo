<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\DynamoDb\Exception\DynamoDbException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class DynamoDb
{
    public static function table(string $name): array
    {
        try {
            return Aws::dynamoDb()->describeTable(['TableName' => $name])['Table'];
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() === 'ResourceNotFoundException') {
                throw new ResourceDoesNotExistException("Could not find DynamoDB table $name");
            }

            throw $exception;
        }
    }
}
