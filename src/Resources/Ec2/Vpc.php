<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared VPC for the environment (not per-app). The 10.1.0.0/16 block is
 * deliberate — it sidesteps a clash with the 10.0.0.0/16 a co-located Vapor
 * stack uses. Point `aws.vpc` at an existing VPC name to adopt rather than
 * create one; SyncVpcStep reports that as CUSTOM_MANAGED.
 */
class Vpc implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.vpc', $this->keyedName());
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ec2::vpc($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::vpc($this->name())['VpcId'];
    }

    public function create(): void
    {
        Aws::ec2()->createVpc([
            'CidrBlock' => '10.1.0.0/16',
            'TagSpecifications' => [
                ['ResourceType' => 'vpc', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }
}
