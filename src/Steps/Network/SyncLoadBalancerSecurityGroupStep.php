<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Network\LoadBalancerSecurityGroup;

/**
 * Provisions the load balancer security group (identity + tags via the
 * LoadBalancerSecurityGroup resource) and reconciles its public HTTP/HTTPS
 * ingress rules. Rules are a separate AWS concept with their own diff surface,
 * so they live here rather than on the resource.
 */
class SyncLoadBalancerSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new LoadBalancerSecurityGroup();
        $dryRun = (bool) Arr::get($options, 'dry-run');

        if (! $securityGroup->exists()) {
            if ($dryRun) {
                return StepResult::WOULD_CREATE;
            }

            $securityGroup->create();

            foreach ($this->expectedRules() as $expectedRule) {
                Aws::ec2()->authorizeSecurityGroupIngress($expectedRule($securityGroup->arn()));
            }

            return StepResult::CREATED;
        }

        if (! $dryRun) {
            $securityGroup->synchroniseTags();
        }

        return $this->reconcileRules($securityGroup->arn(), $dryRun);
    }

    protected function reconcileRules(string $groupId, bool $dryRun): StepResult
    {
        foreach ($this->expectedRules() as $tag => $expectedRule) {
            $liveRules = Ec2::securityGroupRules($groupId, $tag);

            if (empty($liveRules)) {
                if ($dryRun) {
                    return StepResult::OUT_OF_SYNC;
                }

                Aws::ec2()->authorizeSecurityGroupIngress($expectedRule($groupId));

                continue;
            }

            $desired = static::mapRule($expectedRule($groupId)['IpPermissions'][0]);

            if (Helpers::payloadHasDifferences($desired, $liveRules[0])) {
                if ($dryRun) {
                    return StepResult::OUT_OF_SYNC;
                }

                Aws::ec2()->modifySecurityGroupRules([
                    'GroupId' => $groupId,
                    'SecurityGroupRules' => [
                        [
                            'SecurityGroupRuleId' => $liveRules[0]['SecurityGroupRuleId'],
                            'SecurityGroupRule' => [
                                ...$desired,
                                'Description' => $expectedRule($groupId)['IpPermissions'][0]['IpRanges'][0]['Description'],
                            ],
                        ],
                    ],
                ]);
            }
        }

        return StepResult::SYNCED;
    }

    /**
     * The desired ingress rules, keyed by their `yolo:rule-type` marker so each
     * can be looked up and diffed independently.
     *
     * @return array<string, callable(string): array<string, mixed>>
     */
    protected function expectedRules(): array
    {
        return [
            SecurityGroupRule::LOAD_BALANCER_HTTP_RULE->value => fn (string $groupId) => static::publicRule($groupId, 80, 'Allow HTTP from anywhere', SecurityGroupRule::LOAD_BALANCER_HTTP_RULE),
            SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE->value => fn (string $groupId) => static::publicRule($groupId, 443, 'Allow HTTPS from anywhere', SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function publicRule(string $groupId, int $port, string $description, SecurityGroupRule $ruleType): array
    {
        return [
            'GroupId' => $groupId,
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => $port,
                    'ToPort' => $port,
                    'IpRanges' => [
                        ['CidrIp' => '0.0.0.0/0', 'Description' => $description],
                    ],
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group-rule',
                    'Tags' => [
                        ['Key' => 'yolo:rule-type', 'Value' => $ruleType->value],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $permission
     * @return array<string, mixed>
     */
    protected static function mapRule(array $permission): array
    {
        $permission['CidrIpv4'] = $permission['IpRanges'][0]['CidrIp'];
        unset($permission['IpRanges']);

        return $permission;
    }
}
