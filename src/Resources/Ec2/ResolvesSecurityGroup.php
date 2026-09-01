<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * VPC-scoped existence/id lookup shared by every security-group resource.
 * The lookup is scoped to the environment VPC because group names are only
 * unique per VPC — an unscoped match could resolve a same-named group owned
 * by another deployment generation sharing the account (see
 * Ec2::securityGroup()).
 *
 * Greenfield-safe (the two-pass contract): when the environment VPC itself
 * doesn't exist yet, resolving it throws ResourceDoesNotExistException, which
 * exists() reports as "absent" — so a first-ever plan pass reads the group as
 * pending creation instead of crashing.
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
