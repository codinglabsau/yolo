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
use Illuminate\Support\Str;
use Aws\Route53\Route53Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\EventBridge\EventBridgeClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

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
        $tags = static::expectedTags($tags);

        if ($associative) {
            return [$wrap => $tags];
        }

        return [$wrap => static::keyValueTags($tags)];
    }

    /**
     * ECS uses lower-case `key`/`value` tag pairs instead of the standard
     * upper-case `Key`/`Value` shape returned by tags().
     */
    public static function ecsTags(array $tags = []): array
    {
        return static::lowerKeyValueTags(static::expectedTags($tags));
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

    /**
     * The expected tag set for any resource at write time: the resource's own
     * tags plus the `yolo:environment` baseline — *except* on account-scope
     * resources, which are shared across every environment and so deliberately
     * carry no `yolo:environment` label (it would be a false attribution and a
     * teardown hazard). `yolo:scope=account` in the input is the marker for
     * "skip the env baseline".
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    public static function expectedTags(array $tags = []): array
    {
        if (($tags['yolo:scope'] ?? null) === 'account') {
            return $tags;
        }

        return [
            'yolo:environment' => Helpers::app('environment'),
            ...$tags,
        ];
    }

    /**
     * Single source of truth for tag drift — every `synchroniseXxxTags` helper
     * routes through here. `$read` returns whatever the service-specific
     * ListTagsForResource / DescribeTags / GetBucketTagging gives back (any
     * shape `flattenTags` can normalise); `$write` is invoked with the
     * computed delta plus the (already-flattened) current map, so callers
     * whose write API is a full-replace (S3 putBucketTagging) can merge
     * without re-reading. Returns the missing tags so callers can record them
     * as plan-time Changes and decide whether the step needs an apply.
     *
     * @param  array<string, string>  $expected  tags the resource expects (Name, yolo:scope, yolo:app, …)
     * @param  callable(): array  $read  closure returning the current tag list/map from AWS
     * @param  callable(array<string, string>, array<string, string>): void  $write  closure invoked with ($missing, $current) on apply
     * @return array<string, string> missing tag keys (always returned, also when apply=false)
     */
    public static function reconcileTags(array $expected, callable $read, callable $write, bool $apply): array
    {
        $current = static::flattenTags($read());
        $missing = static::tagsRequiringSync(static::expectedTags($expected), $current);

        if ($missing !== [] && $apply) {
            $write($missing, $current);
        }

        return $missing;
    }

    /**
     * Synchronise tags on an ELBv2 resource (load balancer, target group, listener, listener rule).
     *
     * @return array<string, string>
     */
    public static function synchroniseElbV2Tags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::elasticLoadBalancingV2()->describeTags(['ResourceArns' => [$arn]])['TagDescriptions'][0]['Tags'] ?? [],
            fn (array $missing) => static::elasticLoadBalancingV2()->addTags([
                'ResourceArns' => [$arn],
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an ECS resource (cluster, service, task definition).
     * ECS uses lower-case key/value pairs.
     *
     * @return array<string, string>
     */
    public static function synchroniseEcsTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::ecs()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? [],
            fn (array $missing) => static::ecs()->tagResource([
                'resourceArn' => $arn,
                'tags' => static::lowerKeyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an ECR resource. ECR uses upper-case Key/Value inside
     * a `tags` (lower-case) wrap.
     *
     * @return array<string, string>
     */
    public static function synchroniseEcrTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::ecr()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? [],
            fn (array $missing) => static::ecr()->tagResource([
                'resourceArn' => $arn,
                'tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on a CloudWatch Logs resource. DescribeLogGroups returns
     * the ARN with a trailing `:*` (stream wildcard) — strip before calling.
     * `tags` is associative on both read and write.
     *
     * @return array<string, string>
     */
    public static function synchroniseCloudWatchLogsTags(string $arn, array $tags, bool $apply): array
    {
        $arn = (string) preg_replace('/:\*$/', '', $arn);

        return static::reconcileTags(
            $tags,
            fn () => static::cloudWatchLogs()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? [],
            fn (array $missing) => static::cloudWatchLogs()->tagResource([
                'resourceArn' => $arn,
                'tags' => $missing,
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an EC2 resource (security group, subnet, VPC, etc.) by ID.
     *
     * @return array<string, string>
     */
    public static function synchroniseEc2Tags(string $resourceId, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::ec2()->describeTags([
                'Filters' => [['Name' => 'resource-id', 'Values' => [$resourceId]]],
            ])['Tags'] ?? [],
            fn (array $missing) => static::ec2()->createTags([
                'Resources' => [$resourceId],
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an SNS topic, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseSnsTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::sns()->listTagsForResource(['ResourceArn' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::sns()->tagResource([
                'ResourceArn' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an SQS queue, addressed by its URL (not ARN). SQS reads
     * and writes tags as an associative map.
     *
     * @return array<string, string>
     */
    public static function synchroniseSqsTags(string $url, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::sqs()->listQueueTags(['QueueUrl' => $url])['Tags'] ?? [],
            fn (array $missing) => static::sqs()->tagQueue([
                'QueueUrl' => $url,
                'Tags' => $missing,
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an EventBridge rule, addressed by its ARN. EventBridge
     * uses the `ResourceARN` key (capitalised ARN).
     *
     * @return array<string, string>
     */
    public static function synchroniseEventBridgeTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::eventBridge()->listTagsForResource(['ResourceARN' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::eventBridge()->tagResource([
                'ResourceARN' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an ACM certificate, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseAcmTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::acm()->listTagsForCertificate(['CertificateArn' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::acm()->addTagsToCertificate([
                'CertificateArn' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on a Route 53 hosted zone. The zone Id comes back from
     * listHostedZones prefixed (`/hostedzone/Z123`); the tagging API wants the
     * bare id, and adds tags under `AddTags` rather than `Tags`.
     *
     * @return array<string, string>
     */
    public static function synchroniseRoute53Tags(string $id, array $tags, bool $apply): array
    {
        $id = Str::afterLast($id, '/');

        return static::reconcileTags(
            $tags,
            fn () => static::route53()->listTagsForResource([
                'ResourceType' => 'hostedzone',
                'ResourceId' => $id,
            ])['ResourceTagSet']['Tags'] ?? [],
            fn (array $missing) => static::route53()->changeTagsForResource([
                'ResourceType' => 'hostedzone',
                'ResourceId' => $id,
                'AddTags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an RDS resource (e.g. a DB subnet group), addressed by
     * its ARN. RDS reads via `TagList` and writes via `addTagsToResource`.
     *
     * @return array<string, string>
     */
    public static function synchroniseRdsTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::rds()->listTagsForResource(['ResourceName' => $arn])['TagList'] ?? [],
            fn (array $missing) => static::rds()->addTagsToResource([
                'ResourceName' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on a CloudWatch alarm, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseCloudWatchTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::cloudWatch()->listTagsForResource(['ResourceARN' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::cloudWatch()->tagResource([
                'ResourceARN' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an S3 bucket. S3's `putBucketTagging` is a full-replace
     * operation, so a delta-only write isn't possible — we read once, diff, and
     * (if there's a diff) put back the merged set (existing + missing) so
     * manually-added tags survive sync. Empty diff → no write.
     *
     * @return array<string, string>
     */
    public static function synchroniseS3Tags(string $bucket, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            function () use ($bucket) {
                try {
                    return static::s3()->getBucketTagging(['Bucket' => $bucket])['TagSet'] ?? [];
                } catch (\Throwable) {
                    return []; // S3 throws NoSuchTagSet on untagged buckets
                }
            },
            fn (array $missing, array $current) => static::s3()->putBucketTagging([
                'Bucket' => $bucket,
                'Tagging' => ['TagSet' => static::keyValueTags([...$current, ...$missing])],
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an IAM policy, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseIamPolicyTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::iam()->listPolicyTags(['PolicyArn' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::iam()->tagPolicy([
                'PolicyArn' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an IAM role, addressed by its name (IAM tagging APIs
     * key on name, not ARN).
     *
     * @return array<string, string>
     */
    public static function synchroniseIamRoleTags(string $roleName, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::iam()->listRoleTags(['RoleName' => $roleName])['Tags'] ?? [],
            fn (array $missing) => static::iam()->tagRole([
                'RoleName' => $roleName,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on an IAM OIDC identity provider, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseIamOidcProviderTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::iam()->listOpenIDConnectProviderTags(['OpenIDConnectProviderArn' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::iam()->tagOpenIDConnectProvider([
                'OpenIDConnectProviderArn' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Synchronise tags on a CloudFront resource, addressed by its ARN.
     *
     * @return array<string, string>
     */
    public static function synchroniseCloudFrontTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::cloudFront()->listTagsForResource(['Resource' => $arn])['Tags']['Items'] ?? [],
            fn (array $missing) => static::cloudFront()->tagResource([
                'Resource' => $arn,
                'Tags' => ['Items' => static::keyValueTags($missing)],
            ]),
            $apply,
        );
    }

    /**
     * The standard upper-case `[{Key, Value}]` tag-list shape most AWS tagging
     * APIs accept on write, built from an associative {key => value} map.
     */
    protected static function keyValueTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
            ->values()
            ->all();
    }

    /**
     * The lower-case `[{key, value}]` variant — ECS is the lone AWS service that
     * insists on lower-case tag keys on its tagging API.
     */
    protected static function lowerKeyValueTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
            ->values()
            ->all();
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

    public static function resourceGroupsTaggingApi(): ResourceGroupsTaggingAPIClient
    {
        return Helpers::app('resourceGroupsTaggingApi');
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
