<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncListenerRuleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $apex = Manifest::apex();
        $hosts = collect([$apex, "www.$apex"])->unique()->values()->all();

        try {
            $listener = AwsResources::loadBalancerListenerOnPort(443);
        } catch (ResourceDoesNotExistException) {
            // no HTTPS listener yet (cert not issued) — defer
            return StepResult::SKIPPED;
        }

        $existing = static::findRuleForHosts($listener['ListenerArn'], $hosts);

        if ($existing !== null) {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        Aws::elasticLoadBalancingV2()->createRule([
            'ListenerArn' => $listener['ListenerArn'],
            'Priority' => static::priority($listener['ListenerArn']),
            'Conditions' => [
                [
                    'Field' => 'host-header',
                    'HostHeaderConfig' => ['Values' => $hosts],
                ],
            ],
            'Actions' => [
                [
                    'Type' => 'forward',
                    'TargetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                ],
            ],
            ...Aws::tags(['Name' => Helpers::keyedResourceName(exclusive: true)]),
        ]);

        return StepResult::CREATED;
    }

    protected static function findRuleForHosts(string $listenerArn, array $hosts): ?array
    {
        $rules = Aws::elasticLoadBalancingV2()->describeRules([
            'ListenerArn' => $listenerArn,
        ])['Rules'];

        foreach ($rules as $rule) {
            foreach ($rule['Conditions'] ?? [] as $condition) {
                if ($condition['Field'] !== 'host-header') {
                    continue;
                }

                $values = $condition['HostHeaderConfig']['Values'] ?? $condition['Values'] ?? [];

                if (! array_diff($hosts, $values) && ! array_diff($values, $hosts)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    protected static function priority(string $listenerArn): int
    {
        $usedPriorities = collect(Aws::elasticLoadBalancingV2()->describeRules([
            'ListenerArn' => $listenerArn,
        ])['Rules'])
            ->filter(fn (array $rule) => $rule['Priority'] !== 'default')
            ->map(fn (array $rule) => (int) $rule['Priority'])
            ->all();

        $base = (abs(crc32(Helpers::keyedResourceName(exclusive: true))) % 49000) + 1000;

        while (in_array($base, $usedPriorities, true)) {
            $base = ($base % 50000) + 1;
        }

        return $base;
    }
}
