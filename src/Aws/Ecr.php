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
                throw new ResourceDoesNotExistException("Could not find ECR repository $name", $e->getCode(), $e);
            }

            throw $e;
        }

        if (count($repositories) === 0) {
            throw new ResourceDoesNotExistException("Could not find ECR repository $name");
        }

        return $repositories[0];
    }

    /**
     * A missing repository reads as a missing image — on a greenfield plan pass
     * the repo's own create is still pending.
     */
    public static function imageExists(string $repository, string $tag): bool
    {
        try {
            return Aws::ecr()->describeImages([
                'repositoryName' => $repository,
                'imageIds' => [['imageTag' => $tag]],
            ])['imageDetails'] !== [];
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['ImageNotFoundException', 'RepositoryNotFoundException'], true)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * A missing repository yields [] — a greenfield app has nothing to roll back to.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function images(string $repository): array
    {
        $details = [];
        $token = null;

        try {
            do {
                $result = Aws::ecr()->describeImages(array_filter([
                    'repositoryName' => $repository,
                    'nextToken' => $token,
                ]));

                $details = [...$details, ...($result['imageDetails'] ?? [])];
                $token = $result['nextToken'] ?? null;
            } while ($token);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'RepositoryNotFoundException') {
                return [];
            }

            throw $e;
        }

        return $details;
    }
}
