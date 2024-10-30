<?php

namespace Codinglabs\Yolo;

use Aws\S3\S3Client;
use Aws\Acm\AcmClient;
use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Aws\Sts\StsClient;
use Aws\Route53\Route53Client;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\ElasticTranscoder\ElasticTranscoderClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

class Aws
{
    public static function runningInAws(): bool
    {
        return Helpers::app('runningInAws');
    }

    public static function runningInAwsWebEnvironment(): bool
    {
        return Helpers::app('runningInAwsWebEnvironment');
    }

    public static function runningInAwsQueueEnvironment(): bool
    {
        return Helpers::app('runningInAwsQueueEnvironment');
    }

    public static function runningInAwsSchedulerEnvironment(): bool
    {
        return Helpers::app('runningInAwsSchedulerEnvironment');
    }

    public static function tags(array $tags = [], string $wrap = 'Tags'): array
    {
        $tags = [
            'yolo:environment' => Helpers::app('environment'),
            ...$tags,
        ];

        return [
            $wrap => collect($tags)
                ->map(fn ($value, $key) => [
                    'Key' => $key,
                    'Value' => $value,
                ])
                ->values()
                ->all(),
        ];
    }


    public static function accountId(): string
    {
        return Aws::sts()->getAccessKeyInfo([
            'AccessKeyId' => Aws::s3()
                ->getCredentials()
                ->wait()
                ->getAccessKeyId(),
        ])['Account'];
    }

    public static function acm(): AcmClient
    {
        return Helpers::app('acm');
    }

    public static function autoscaling(): AutoScalingClient
    {
        return Helpers::app('autoscaling');
    }

    public static function cloudWatch(): CloudWatchClient
    {
        return Helpers::app('cloudWatch');
    }

    public static function codeDeploy(): CodeDeployClient
    {
        return Helpers::app('codeDeploy');
    }

    public static function ec2(): Ec2Client
    {
        return Helpers::app('ec2');
    }

    public static function elasticLoadBalancingV2(): ElasticLoadBalancingV2Client
    {
        return Helpers::app('elasticLoadBalancingV2');
    }

    public static function elasticTranscoder(): ElasticTranscoderClient
    {
        return Helpers::app('elasticTranscoder');
    }

    public static function rds(): RdsClient
    {
        return Helpers::app('rds');
    }

    public static function route53(): Route53Client
    {
        return Helpers::app('route53');
    }

    public static function s3(): S3Client
    {
        return Helpers::app('s3');
    }

    public static function sns(): SnsClient
    {
        return Helpers::app('sns');
    }

    public static function sqs(): SqsClient
    {
        return Helpers::app('sqs');
    }

    public static function sts(): StsClient
    {
        return Helpers::app('sts');
    }
}
