<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEc2RoleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::ec2Role();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            $name = Helpers::keyedResourceName(exclusive: false);

            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createRole([
                    'RoleName' => $name,
                    'AssumeRolePolicyDocument' => json_encode([
                            "Version" => "2012-10-17",
                            "Statement" => [
                                [
                                    "Effect" => "Allow",
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
                                    "Resource" => "*",
                                ],
                                [
                                    "Action" => [
                                        "iam:PassRole",
                                    ],
                                    "Resource" => [
                                        "arn:aws:iam::*:role/Elastic_Transcoder_Default_Role",
                                    ],
                                    "Effect" => "Allow",
                                ],
                                [
                                    "Action" => [
                                        "s3:ListBucket",
                                        "s3:GetObject",
                                        "s3:PutObject",
                                    ],
                                    "Resource" => [
                                        sprintf('arn:aws:s3:::%s', Paths::s3ArtefactsBucket()),
                                        sprintf('arn:aws:s3:::%s/*', Paths::s3ArtefactsBucket()),
                                    ],
                                    "Effect" => "Allow",
                                ],
                                [
                                    "Effect" => "Allow",
                                    "Action" => [
                                        "s3:PutObject",
                                        "s3:GetObject",
                                        "s3:ListBucket",
                                        "s3:DeleteObject",
                                        "s3:GetObjectAcl",
                                        "s3:PutObjectAcl",
                                        "s3:GetObjectAttributes",
                                    ],
                                    "Resource" => [
                                        sprintf('arn:aws:s3:::%s', Paths::s3AppBucket()),
                                        sprintf('arn:aws:s3:::%s/*', Paths::s3AppBucket()),
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'Description' => 'YOLO managed EC2 role',
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
