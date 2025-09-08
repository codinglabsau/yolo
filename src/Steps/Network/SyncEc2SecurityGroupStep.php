<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEc2SecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $expectedRules = [
            SecurityGroupRule::LOAD_BALANCER_INGRESS_RULE->value => fn (array $securityGroup) => static::loadBalanceIngressRule($securityGroup),
            SecurityGroupRule::SSH_INGRESS_RULE->value => fn (array $securityGroup) => static::sshIngressRule($securityGroup),
        ];

        try {
            $securityGroup = AwsResources::ec2SecurityGroup();

            if (Manifest::get('aws.ec2.security-group')) {
                return StepResult::CUSTOM_MANAGED;
            }

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
                } elseif (static::rulesAreDifferent($expectedRule($securityGroup)['IpPermissions'][0], $securityGroupRules[0])) {
                    if (! Arr::get($options, 'dry-run')) {
                        $payload = $expectedRule($securityGroup)['IpPermissions'][0];

                        if (isset($payload['UserIdGroupPairs'])) {
                            $payload['ReferencedGroupId'] = $payload['UserIdGroupPairs'][0]['GroupId'];
                            $payload['Description'] = $payload['UserIdGroupPairs'][0]['Description'];

                            unset($payload['UserIdGroupPairs']);
                        }

                        if (isset($payload['IpRanges'])) {
                            $payload['CidrIpv4'] = $payload['IpRanges'][0]['CidrIp'];
                            $payload['Description'] = $payload['IpRanges'][0]['Description'];

                            unset($payload['IpRanges']);
                        }

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
                if (Manifest::get('aws.ec2.security-group')) {
                    throw IntegrityCheckException::make('yolo.yml specifies a custom EC2 security group which does not exist');
                }

                $name = Helpers::keyedResourceName(SecurityGroup::EC2_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable load balancer and SSH traffic',
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

                $securityGroup = AwsResources::ec2SecurityGroup();

                foreach ($expectedRules as $expectedRule) {
                    Aws::ec2()->authorizeSecurityGroupIngress($expectedRule($securityGroup));
                }

                return StepResult::CREATED;
            }

            return Manifest::get('aws.ec2.security-group')
                ? StepResult::MANIFEST_INVALID
                : StepResult::WOULD_CREATE;
        }
    }

    protected static function loadBalanceIngressRule(array $securityGroup): array
    {
        return [
            'GroupId' => $securityGroup['GroupId'],
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => 80,
                    'ToPort' => 80,
                    'UserIdGroupPairs' => [
                        [
                            'GroupId' => AwsResources::loadBalancerSecurityGroup()['GroupId'],
                            'Description' => 'HTTP ingress from the load balancer',
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
                            'Value' => SecurityGroupRule::LOAD_BALANCER_INGRESS_RULE->value,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function sshIngressRule(array $securityGroup): array
    {
        $publicIp = file_get_contents('https://api.ipify.org');

        return [
            'GroupId' => $securityGroup['GroupId'],
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => 22,
                    'ToPort' => 22,
                    'IpRanges' => [
                        [
                            'CidrIp' => "$publicIp/32",
                            'Description' => 'YOLO-determined public IP during sync. Delete if unused.',
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
                            'Value' => SecurityGroupRule::SSH_INGRESS_RULE->value,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function rulesAreDifferent(array $expectedRule, array $rule): bool
    {
        if (isset($expectedRule['UserIdGroupPairs'])) {
            // map to ReferencedGroupInfo
            $expectedRule['ReferencedGroupInfo'] = [
                'GroupId' => $expectedRule['UserIdGroupPairs'][0]['GroupId'],
                'UserId' => $rule['GroupOwnerId'],
            ];
            unset($expectedRule['UserIdGroupPairs']);
        }

        if (isset($expectedRule['IpRanges'])) {
            // map existing IP to expected IP
            $expectedRule['CidrIpv4'] = $rule['CidrIpv4'];
            unset($expectedRule['IpRanges']);
        }

        return Helpers::payloadHasDifferences($expectedRule, $rule);
    }
}
