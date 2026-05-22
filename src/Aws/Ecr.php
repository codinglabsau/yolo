<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Ecr
{
    public static function repository(string $name): array
    {
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

        return $repositories[0];
    }
}
