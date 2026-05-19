<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEcr
{
    protected static array $ecrRepository;

    public static function ecrRepositoryName(): string
    {
        return Manifest::name();
    }

    public static function ecrRepositoryUri(): string
    {
        return sprintf(
            '%s.dkr.ecr.%s.amazonaws.com/%s',
            Aws::accountId(),
            Manifest::get('aws.region'),
            static::ecrRepositoryName(),
        );
    }

    public static function ecrRepository(bool $refresh = false): array
    {
        if (! $refresh && isset(static::$ecrRepository)) {
            return static::$ecrRepository;
        }

        try {
            $repositories = Aws::ecr()->describeRepositories([
                'repositoryNames' => [static::ecrRepositoryName()],
            ])['repositories'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'RepositoryNotFoundException') {
                throw new ResourceDoesNotExistException(sprintf('Could not find ECR repository %s', static::ecrRepositoryName()));
            }

            throw $e;
        }

        if (count($repositories) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find ECR repository %s', static::ecrRepositoryName()));
        }

        static::$ecrRepository = $repositories[0];

        return static::$ecrRepository;
    }
}
