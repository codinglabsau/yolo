<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesIam
{
    public static function ec2Policy(): array
    {
        $name = Helpers::keyedResourceName(exclusive: false);
        $policies = Aws::iam()->listPolicies([
            'Scope' => 'Local',
        ]);

        foreach ($policies['Policies'] as $policy) {
            if ($policy['PolicyName'] === $name) {
                return $policy;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM policy with name $name");
    }

    public static function ec2Role(): array
    {
        $name = Helpers::keyedResourceName(exclusive: false);
        $roles = Aws::iam()->listRoles();

        foreach ($roles['Roles'] as $role) {
            if ($role['RoleName'] === $name) {
                return $role;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM role with name $name");
    }

    public static function instanceProfile(): array
    {
        $name = Helpers::keyedResourceName(exclusive: false);
        $instanceProfiles = Aws::iam()->listInstanceProfiles();

        foreach ($instanceProfiles['InstanceProfiles'] as $instanceProfile) {
            if ($instanceProfile['InstanceProfileName'] === $name) {
                return $instanceProfile;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM instance profile with name $name");
    }

    public static function policyDocument(): array
    {
        return [
            "Version" => "2012-10-17",
            "Statement" => [
                [
                    "Effect" => "Allow",
                    "Resource" => "*",
                    "Action" => [
                        "autoscaling:AttachTrafficSources",
                        "autoscaling:DescribeAutoScalingGroups",
                        "autoscaling:DescribeLoadBalancerTargetGroups",
                        "elasticloadbalancing:DescribeTargetGroups",
                        "ec2:DescribeTags",
                        "elasticloadbalancing:DescribeLoadBalancers",
                        "elastictranscoder:ListPipelines",
                        "sqs:DeleteMessage",
                        "sqs:GetQueueUrl",
                        "sqs:ChangeMessageVisibility",
                        "sqs:ReceiveMessage",
                        "sqs:SendMessage",
                        "sqs:GetQueueAttributes",
                        "sqs:PurgeQueue",
                        "sqs:ListQueues",
                    ],
                ],
                [
                    "Effect" => "Allow",
                    "Resource" => [
                        "arn:aws:iam::*:role/Elastic_Transcoder_Default_Role",
                    ],
                    "Action" => [
                        "iam:PassRole",
                    ],
                ],
                [
                    "Effect" => "Allow",
                    "Resource" => "*",
                    "Action" => [
                        "s3:PutObject",
                        "s3:GetObject",
                        "s3:ListBucket",
                        "s3:DeleteObject",
                        "s3:GetObjectAcl",
                        "s3:PutObjectAcl",
                        "s3:GetObjectAttributes",
                    ],
                ],
                // todo: the following policies follow least priviledge principle to limit access to only necessary buckets, however we are
                // todo: limited by the fact that multiple apps can share the same EC2 instance, and require access to a range of buckets.
//                              [
//                                  "Effect" => "Allow",
//                                    "Resource" => [
//                                        sprintf('arn:aws:s3:::%s', Paths::s3ArtefactsBucket()),
//                                        sprintf('arn:aws:s3:::%s/*', Paths::s3ArtefactsBucket()),
//                                    ],
//                                    "Action" => [
//                                        "s3:ListBucket",
//                                        "s3:GetObject",
//                                        "s3:PutObject",
//                                    ],
//                                ],
                // todo: as above, this policy provides access to the current app bucket only
//                              [
//                                  "Effect" => "Allow",
//                                      "Resource" => [
//                                          sprintf('arn:aws:s3:::%s', Paths::s3AppBucket()),
//                                          sprintf('arn:aws:s3:::%s/*', Paths::s3AppBucket()),
//                                      ],
//                                  "Action" => [
//                                      "s3:PutObject",
//                                      "s3:GetObject",
//                                      "s3:ListBucket",
//                                      "s3:DeleteObject",
//                                      "s3:GetObjectAcl",
//                                      "s3:PutObjectAcl",
//                                      "s3:GetObjectAttributes",
//                                   ],
//                               ],
            ],
        ];
    }

    public static function rolePolicyDocument(): array
    {
        return [
            "Version" => "2012-10-17",
            "Statement" => [
                [
                    "Effect" => "Allow",
                    "Principal" => [
                        "Service" => "ec2.amazonaws.com"
                    ],
                    "Action" => "sts:AssumeRole"
                ],
            ]
        ];
    }
}
