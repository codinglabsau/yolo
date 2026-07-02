<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\PrivateRouteTable;

/**
 * Creates the private route table — VPC-local route only, so the private tier
 * has no path to the internet. Adopted `private-subnets` keep whatever routing
 * their owner gave them, so this step never creates a table to rewire them.
 */
class SyncPrivateRouteTableStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::has('private-subnets')) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource(new PrivateRouteTable(), $options);
    }
}
