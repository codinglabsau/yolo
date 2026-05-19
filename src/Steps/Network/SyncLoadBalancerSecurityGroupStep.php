<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLoadBalancerSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $expectedRules = [
            SecurityGroupRule::LOAD_BALANCER_HTTP_RULE->value => fn (array $securityGroup) => static::httpRule($securityGroup),
            SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE->value => fn (array $securityGroup) => static::httpsRule($securityGroup),
        ];

        try {
            $securityGroup = AwsResources::loadBalancerSecurityGroup();

            foreach ($expectedRules as $tag => $expectedRule) {
                $securityGroupRules = Aws::ec2()->describeSecurityGroupRules([
                    'Filters' => [
                        [
                            'Name' => 'group-id',
                            'Values' => [$securityGroup['GroupId']],
                        ],
                        [
                            'Name' => 'tag:yolo:rule-type',
                            'Values' => [$tag],
                        ],
                    ],
                ])['SecurityGroupRules'];

                if (empty($securityGroupRules)) {
                    // if a rule is missing, add it
                    if (! Arr::get($options, 'dry-run')) {
                        Aws::ec2()->authorizeSecurityGroupIngress($expectedRule($securityGroup));
                    } else {
                        return StepResult::OUT_OF_SYNC;
                    }
                } elseif (static::rulesAreDifferent(static::mapRule($expectedRule($securityGroup)['IpPermissions'][0]), $securityGroupRules[0])) {
                    if (! Arr::get($options, 'dry-run')) {
                        $payload = [
                            ...static::mapRule($expectedRule($securityGroup)['IpPermissions'][0]),
                            'Description' => $expectedRule($securityGroup)['IpPermissions'][0]['IpRanges'][0]['Description'],
                        ];

                        Aws::ec2()->modifySecurityGroupRules([
                            'GroupId' => $securityGroup['GroupId'],
                            'SecurityGroupRules' => [
                                [
                                    'SecurityGroupRule' => $payload,
                                    'SecurityGroupRuleId' => $securityGroupRules[0]['SecurityGroupRuleId'],
                                ],
                            ],
                        ]);
                    } else {
                        return StepResult::OUT_OF_SYNC;
                    }
                }
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable HTTP and HTTPS from anywhere',
                    'GroupName' => $name,
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'security-group',
                            ...Aws::tags([
                                'Name' => $name,
                            ]),
                        ],
                    ],
                ]);

                $securityGroup = AwsResources::loadBalancerSecurityGroup();

                foreach ($expectedRules as $expectedRule) {
                    Aws::ec2()->authorizeSecurityGroupIngress($expectedRule($securityGroup));
                }

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    protected static function httpRule(array $securityGroup): array
    {
        return [
            'GroupId' => $securityGroup['GroupId'],
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => 80,
                    'ToPort' => 80,
                    'IpRanges' => [
                        [
                            'CidrIp' => '0.0.0.0/0',
                            'Description' => 'Allow HTTP from anywhere',
                        ],
                    ],
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group-rule',
                    'Tags' => [
                        [
                            'Key' => 'yolo:rule-type',
                            'Value' => SecurityGroupRule::LOAD_BALANCER_HTTP_RULE->value,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function httpsRule(array $securityGroup): array
    {
        return [
            'GroupId' => $securityGroup['GroupId'],
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => 443,
                    'ToPort' => 443,
                    'IpRanges' => [
                        [
                            'CidrIp' => '0.0.0.0/0',
                            'Description' => 'Allow HTTPS from anywhere',
                        ],
                    ],
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group-rule',
                    'Tags' => [
                        [
                            'Key' => 'yolo:rule-type',
                            'Value' => SecurityGroupRule::LOAD_BALANCER_HTTPS_RULE->value,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function rulesAreDifferent(array $expectedRule, array $rule): bool
    {
        return Helpers::payloadHasDifferences($expectedRule, $rule);
    }

    protected static function mapRule(array $rule): array
    {
        $rule['CidrIpv4'] = $rule['IpRanges'][0]['CidrIp'];
        unset($rule['IpRanges']);

        return $rule;
    }
}
