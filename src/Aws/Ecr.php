<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Ecr
{
    /** @var array<string, array<string, mixed>> */
    protected static array $repositories = [];

    public static function repository(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$repositories[$name])) {
            return static::$repositories[$name];
        }

        try {
            $repositories = Aws::ecr()->describeRepositories([
                'repositoryNames' => [$name],
            ])['repositories'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'RepositoryNotFoundException') {
                throw new ResourceDoesNotExistException("Could not find ECR repository $name");
            }

            throw $e;
        }

        if (count($repositories) === 0) {
            throw new ResourceDoesNotExistException("Could not find ECR repository $name");
        }

        return static::$repositories[$name] = $repositories[0];
    }
}
