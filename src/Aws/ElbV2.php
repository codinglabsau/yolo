<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class ElbV2
{
    public static function loadBalancer(string $name): array
    {
        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === $name) {
                return $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException("Could not find load balancer $name");
    }

    public static function targetGroup(string $name): array
    {
        try {
            $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups([
                'Names' => [$name],
            ])['TargetGroups'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'TargetGroupNotFound') {
                throw new ResourceDoesNotExistException("Could not find target group $name", $e->getCode(), $e);
            }

            throw $e;
        }

        if (count($targetGroups) === 0) {
            throw new ResourceDoesNotExistException("Could not find target group $name");
        }

        return $targetGroups[0];
    }

    public static function listenerOnPort(string $loadBalancerArn, int $port): array
    {
        $listeners = Aws::elasticLoadBalancingV2()->describeListeners([
            'LoadBalancerArn' => $loadBalancerArn,
        ]);

        foreach ($listeners['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return $listener;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener on port $port");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rules(string $listenerArn): array
    {
        return Aws::elasticLoadBalancingV2()->describeRules([
            'ListenerArn' => $listenerArn,
        ])['Rules'];
    }

    public static function listenerCertificate(string $listenerArn, string $certificateArn): array
    {
        $certificates = Aws::elasticLoadBalancingV2()->describeListenerCertificates([
            'ListenerArn' => $listenerArn,
        ])['Certificates'];

        foreach ($certificates as $certificate) {
            if ($certificate['CertificateArn'] === $certificateArn) {
                return $certificate;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener certificate on listener $listenerArn");
    }

    /**
     * The listener rule carrying the given `Name` tag, or null. Rules have no
     * native name, so identity lives in the Name tag — read here in tag-batches of
     * 20 (the DescribeTags ARN limit). Matching by Name (not by the hosts a rule
     * routes) is what keeps a domain change scoped to this app's own rule and
     * never a sibling host's.
     *
     * @return array<string, mixed>|null
     */
    public static function ruleByName(string $listenerArn, string $name): ?array
    {
        $rules = array_values(array_filter(
            static::rules($listenerArn),
            fn (array $rule): bool => $rule['Priority'] !== 'default',
        ));

        if ($rules === []) {
            return null;
        }

        $nameByArn = [];

        foreach (array_chunk(array_column($rules, 'RuleArn'), 20) as $chunk) {
            foreach (Aws::elasticLoadBalancingV2()->describeTags(['ResourceArns' => $chunk])['TagDescriptions'] as $description) {
                $nameByArn[$description['ResourceArn']] = collect($description['Tags'] ?? [])
                    ->firstWhere('Key', 'Name')['Value'] ?? null;
            }
        }

        foreach ($rules as $rule) {
            if (($nameByArn[$rule['RuleArn']] ?? null) === $name) {
                return $rule;
            }
        }

        return null;
    }

    public static function deleteRule(string $ruleArn): void
    {
        Aws::elasticLoadBalancingV2()->deleteRule(['RuleArn' => $ruleArn]);
    }
}
