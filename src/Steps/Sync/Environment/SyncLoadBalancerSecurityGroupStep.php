<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;

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

        // Surface tag drift (e.g. the yolo:scope marker) the way
        // SynchronisesResource does: compute it regardless of --dry-run so the
        // plan lists it and the apply pass isn't dropped by the
        // only-pending-steps filter; the write happens only when applying.
        $drifted = false;

        foreach ($this->synchroniseOwnedTags($securityGroup, $dryRun) as $key => $value) {
            $this->recordChange(Change::make("tag {$key}", null, $value));
            $drifted = true;
        }

        return $this->reconcileRules($securityGroup->arn(), $dryRun, $drifted);
    }

    protected function reconcileRules(string $groupId, bool $dryRun, bool $drifted = false): StepResult
    {
        foreach ($this->expectedRules() as $tag => $expectedRule) {
            $rule = $expectedRule($groupId);
            $permission = $rule['IpPermissions'][0];
            $label = sprintf('ingress %d/tcp from %s', $permission['FromPort'], $permission['IpRanges'][0]['CidrIp']);

            $liveRules = Ec2::securityGroupRules($groupId, $tag);

            if ($liveRules === []) {
                $drifted = true;
                $this->recordChange(Change::make($label, null, 'authorised'));

                if (! $dryRun) {
                    Aws::ec2()->authorizeSecurityGroupIngress($rule);
                }

                continue;
            }

            $desired = static::mapRule($permission);

            if (Helpers::payloadHasDifferences($desired, $liveRules[0])) {
                $drifted = true;
                $this->recordChange(Change::make($label, 'drifted', 'reconciled'));

                if (! $dryRun) {
                    Aws::ec2()->modifySecurityGroupRules([
                        'GroupId' => $groupId,
                        'SecurityGroupRules' => [
                            [
                                'SecurityGroupRuleId' => $liveRules[0]['SecurityGroupRuleId'],
                                'SecurityGroupRule' => [
                                    ...$desired,
                                    'Description' => $permission['IpRanges'][0]['Description'],
                                ],
                            ],
                        ],
                    ]);
                }
            }
        }

        return $drifted && $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
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
            SecurityGroupRule::LOAD_BALANCER_HTTP_RULE->value => fn (string $groupId): array => static::publicRule($groupId, 80, 'Allow HTTP from anywhere', SecurityGroupRule::LOAD_BALANCER_HTTP_RULE),
            SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE->value => fn (string $groupId): array => static::publicRule($groupId, 443, 'Allow HTTPS from anywhere', SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE),
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
