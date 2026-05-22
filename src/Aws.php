<?php

namespace Codinglabs\Yolo;

use Aws\S3\S3Client;
use Aws\Acm\AcmClient;
use Aws\Ec2\Ec2Client;
use Aws\Ecr\EcrClient;
use Aws\Ecs\EcsClient;
use Aws\Iam\IamClient;
use Aws\Rds\RdsClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use Aws\Route53\Route53Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\EventBridge\EventBridgeClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
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

    public static function tags(array $tags = [], string $wrap = 'Tags', bool $associative = false): array
    {
        $tags = [
            'yolo:environment' => Helpers::app('environment'),
            ...$tags,
        ];

        if ($associative) {
            return [$wrap => $tags];
        }

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

    /**
     * ECS uses lower-case `key`/`value` tag pairs instead of the standard
     * upper-case `Key`/`Value` shape returned by tags().
     */
    public static function ecsTags(array $tags = []): array
    {
        return collect([
            'yolo:environment' => Helpers::app('environment'),
            ...$tags,
        ])
            ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * Returns the subset of expected tags that are missing or stale on the
     * resource. Synchronisation is additive — tags YOLO does not manage are
     * left alone so manually-applied tags survive sync.
     */
    public static function tagsRequiringSync(array $expected, array $current): array
    {
        return collect($expected)
            ->filter(fn ($value, $key) => ($current[$key] ?? null) !== $value)
            ->all();
    }

    /**
     * Normalises a tag list returned by any AWS service ([{Key, Value}],
     * [{key, value}] or associative {key: value}) into an associative
     * array suitable for diffing.
     */
    public static function flattenTags(array $tags): array
    {
        if (array_is_list($tags)) {
            return collect($tags)
                ->mapWithKeys(fn (array $tag) => [
                    ($tag['Key'] ?? $tag['key']) => ($tag['Value'] ?? $tag['value']),
                ])
                ->all();
        }

        return $tags;
    }

    public static function expectedTags(array $tags = []): array
    {
        return [
            'yolo:environment' => Helpers::app('environment'),
            ...$tags,
        ];
    }

    /**
     * Synchronise tags on an ELBv2 resource (load balancer, target group, listener, listener rule).
     */
    public static function synchroniseElbV2Tags(string $arn, array $tags = []): void
    {
        $current = static::flattenTags(
            static::elasticLoadBalancingV2()->describeTags(['ResourceArns' => [$arn]])['TagDescriptions'][0]['Tags'] ?? []
        );

        $missing = static::tagsRequiringSync(static::expectedTags($tags), $current);

        if (empty($missing)) {
            return;
        }

        static::elasticLoadBalancingV2()->addTags([
            'ResourceArns' => [$arn],
            'Tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Synchronise tags on an ECS resource (cluster, service, task definition).
     * ECS uses lower-case key/value pairs.
     */
    public static function synchroniseEcsTags(string $arn, array $tags = []): void
    {
        $current = static::flattenTags(
            static::ecs()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? []
        );

        $missing = static::tagsRequiringSync(static::expectedTags($tags), $current);

        if (empty($missing)) {
            return;
        }

        static::ecs()->tagResource([
            'resourceArn' => $arn,
            'tags' => collect($missing)
                ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Synchronise tags on an ECR resource. ECR uses upper-case Key/Value inside
     * a `tags` (lower-case) wrap.
     */
    public static function synchroniseEcrTags(string $arn, array $tags = []): void
    {
        $current = static::flattenTags(
            static::ecr()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? []
        );

        $missing = static::tagsRequiringSync(static::expectedTags($tags), $current);

        if (empty($missing)) {
            return;
        }

        static::ecr()->tagResource([
            'resourceArn' => $arn,
            'tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Synchronise tags on a CloudWatch Logs resource. DescribeLogGroups returns
     * the ARN with a trailing `:*` (stream wildcard) — strip before calling.
     * `tags` is associative on both read and write.
     */
    public static function synchroniseCloudWatchLogsTags(string $arn, array $tags = []): void
    {
        $arn = (string) preg_replace('/:\*$/', '', $arn);

        $current = static::flattenTags(
            static::cloudWatchLogs()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? []
        );

        $missing = static::tagsRequiringSync(static::expectedTags($tags), $current);

        if (empty($missing)) {
            return;
        }

        static::cloudWatchLogs()->tagResource([
            'resourceArn' => $arn,
            'tags' => $missing,
        ]);
    }

    /**
     * Synchronise tags on an EC2 resource (security group, subnet, VPC, etc.) by ID.
     */
    public static function synchroniseEc2Tags(string $resourceId, array $tags = []): void
    {
        $current = static::flattenTags(
            static::ec2()->describeTags([
                'Filters' => [['Name' => 'resource-id', 'Values' => [$resourceId]]],
            ])['Tags'] ?? []
        );

        $missing = static::tagsRequiringSync(static::expectedTags($tags), $current);

        if (empty($missing)) {
            return;
        }

        static::ec2()->createTags([
            'Resources' => [$resourceId],
            'Tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }

    public static function accountId(): string
    {
        return Manifest::get('aws.account-id');
    }

    public static function profileAccountId(): string
    {
        return static::sts()->getCallerIdentity()['Account'];
    }

    public static function acm(): AcmClient
    {
        return Helpers::app('acm');
    }

    public static function autoscaling(): AutoScalingClient
    {
        return Helpers::app('autoscaling');
    }

    public static function cloudFront(): CloudFrontClient
    {
        return Helpers::app('cloudFront');
    }

    public static function cloudWatch(): CloudWatchClient
    {
        return Helpers::app('cloudWatch');
    }

    public static function cloudWatchLogs(): CloudWatchLogsClient
    {
        return Helpers::app('cloudWatchLogs');
    }

    public static function codeDeploy(): CodeDeployClient
    {
        return Helpers::app('codeDeploy');
    }

    public static function ec2(): Ec2Client
    {
        return Helpers::app('ec2');
    }

    public static function ecr(): EcrClient
    {
        return Helpers::app('ecr');
    }

    public static function ecs(): EcsClient
    {
        return Helpers::app('ecs');
    }

    public static function elasticLoadBalancingV2(): ElasticLoadBalancingV2Client
    {
        return Helpers::app('elasticLoadBalancingV2');
    }

    public static function eventBridge(): EventBridgeClient
    {
        return Helpers::app('eventBridge');
    }

    public static function iam(): IamClient
    {
        return Helpers::app('iam');
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

    public static function ssm(): SsmClient
    {
        return Helpers::app('ssm');
    }

    public static function sts(): StsClient
    {
        return Helpers::app('sts');
    }
}
