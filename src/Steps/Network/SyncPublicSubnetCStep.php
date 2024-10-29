<?php

namespace Codinglabs\Yolo\Steps\Network;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Concerns\CreatesSubnets;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncPublicSubnetCStep implements Step
{
    use CreatesSubnets;

    public function __invoke(array $options): StepResult
    {
        $publicSubnetName = PublicSubnets::PUBLIC_SUBNET_C->value;

        try {
            AwsResources::subnetByName($publicSubnetName);
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                $this->createSubnet($publicSubnetName, index: 2);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
