<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Ssm\Exception\SsmException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Thin wrapper around the SSM SDK client. Parameter Store is YOLO's keeper of
 * generated service secrets (e.g. the Meilisearch master key) — referenced by
 * ECS task definitions via `secrets`, so the value never sits in a task
 * definition or manifest.
 */
class Ssm
{
    /**
     * @return array<string, mixed>
     */
    public static function parameter(string $name, bool $decrypt = false): array
    {
        try {
            return Aws::ssm()->getParameter([
                'Name' => $name,
                'WithDecryption' => $decrypt,
            ])['Parameter'];
        } catch (SsmException $e) {
            if ($e->getAwsErrorCode() === 'ParameterNotFound') {
                throw new ResourceDoesNotExistException("Could not find SSM parameter $name", $e->getCode(), $e);
            }

            throw $e;
        }
    }
}
