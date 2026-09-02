<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Scoped to the environment VPC because group names are only unique per VPC — an
 * unscoped match could resolve a same-named group from another deployment sharing
 * the account. Greenfield-safe: when the VPC doesn't exist yet the lookup throws
 * ResourceDoesNotExistException, which exists() reports as absent rather than
 * crashing the plan pass.
 *
 * @phpstan-require-implements \Codinglabs\Yolo\Resources\Resource
 */
trait ResolvesSecurityGroup
{
    public function exists(): bool
    {
        try {
            $this->liveSecurityGroup();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return $this->liveSecurityGroup()['GroupId'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function liveSecurityGroup(): array
    {
        return Ec2::securityGroup($this->name(), (new Vpc())->arn());
    }
}
