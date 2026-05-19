<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncListenerRuleStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('apex') && ! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        $hosts = static::routedHosts();

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

        return static::nextAvailablePriority(Helpers::keyedResourceName(exclusive: true), $usedPriorities);
    }

    public static function routedHosts(): array
    {
        $apex = Manifest::apex();
        $domain = Manifest::get('domain', $apex);

        // Apex deploy: route apex + www.apex (both are reasonable inbound hostnames).
        // Subdomain deploy (apex != domain): route only the literal domain.
        return $domain === $apex
            ? [$apex, "www.$apex"]
            : [$domain];
    }

    public static function nextAvailablePriority(string $name, array $usedPriorities): int
    {
        $floor = 1000;
        $ceiling = 49999;
        $range = $ceiling - $floor + 1;

        $base = (abs(crc32($name)) % $range) + $floor;

        for ($attempts = 0; in_array($base, $usedPriorities, true); $attempts++) {
            if ($attempts >= $range) {
                throw new IntegrityCheckException('ALB listener rule priority space (1000-49999) exhausted');
            }

            $base = $base >= $ceiling ? $floor : $base + 1;
        }

        return $base;
    }
}
