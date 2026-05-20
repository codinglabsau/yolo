<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpListenerStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            $listener = AwsResources::loadBalancerListenerOnPort(80);

            $this->reconcileTags($listener['ListenerArn'], Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::elasticLoadBalancingV2()->createListener([
                'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
                'Protocol' => 'HTTP',
                'Port' => 80,
                'DefaultActions' => [
                    [
                        'Type' => 'redirect',
                        'RedirectConfig' => [
                            'Protocol' => 'HTTPS',
                            'Port' => '443',
                            'Host' => '#{host}',
                            'Path' => '/#{path}',
                            'Query' => '#{query}',
                            'StatusCode' => 'HTTP_301',
                        ],
                    ],
                ],
                ...Aws::tags(['Name' => static::name()]),
            ]);

            return StepResult::CREATED;
        }
    }

    protected static function name(): string
    {
        return Helpers::keyedResourceName('http', exclusive: false);
    }

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $current = Aws::flattenTags(
            Aws::elasticLoadBalancingV2()->describeTags(['ResourceArns' => [$arn]])['TagDescriptions'][0]['Tags'] ?? []
        );

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => static::name()]),
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
