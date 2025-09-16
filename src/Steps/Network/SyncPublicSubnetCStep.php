<?php

namespace Codinglabs\Yolo\Steps\Network;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
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
        $publicSubnetName = Manifest::has('aws.public-subnets')
            ? Manifest::get('aws.public-subnets')[2]
            : PublicSubnets::PUBLIC_SUBNET_C->value;

        try {
            AwsResources::subnetByName($publicSubnetName, relative: Manifest::doesntHave('aws.public-subnets'));

            if (Manifest::has('aws.public-subnets')) {
                return StepResult::CUSTOM_MANAGED;
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                $this->createSubnet(PublicSubnets::PUBLIC_SUBNET_C->value, index: 2);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
