<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLoadBalancerStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            $loadBalancer = AwsResources::loadBalancer();

            $this->reconcileTags($loadBalancer['LoadBalancerArn'], Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $name = Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));

            Aws::elasticLoadBalancingV2()->createLoadBalancer([
                'Name' => $name,
                'Type' => 'application',
                'Scheme' => 'internet-facing',
                'IpAddressType' => 'ipv4',
                'SecurityGroups' => [
                    AwsResources::loadBalancerSecurityGroup()['GroupId'],
                ],
                'Subnets' => AwsResources::publicSubnetIds(),
                ...Aws::tags(['Name' => $name]),
            ]);

            return StepResult::CREATED;
        }
    }

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $name = Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));

        $current = Aws::flattenTags(
            Aws::elasticLoadBalancingV2()->describeTags(['ResourceArns' => [$arn]])['TagDescriptions'][0]['Tags'] ?? []
        );

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => $name]),
            $current,
        );

        if (empty($missing) || $dryRun) {
            return;
        }

        Aws::elasticLoadBalancingV2()->addTags([
            'ResourceArns' => [$arn],
            'Tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }
}
