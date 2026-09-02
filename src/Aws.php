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
use Aws\Sts\StsClient;
use Aws\WAFV2\WAFV2Client;
use Aws\AwsClientInterface;
use Illuminate\Support\Str;
use Aws\Route53\Route53Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\ElastiCache\ElastiCacheClient;
use Aws\EventBridge\EventBridgeClient;
use Aws\CostExplorer\CostExplorerClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\ServiceDiscovery\ServiceDiscoveryClient;
use Aws\ApplicationAutoScaling\ApplicationAutoScalingClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

class Aws
{
    public static function runningInAws(): bool
    {
        return Helpers::app('runningInAws');
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
     * @return array<string, bool>
     */
    public static function publicAccessBlockConfiguration(): array
    {
        return [
            'BlockPublicAcls' => true,
            'IgnorePublicAcls' => true,
            'BlockPublicPolicy' => true,
            'RestrictPublicBuckets' => true,
        ];
    }

    /** ECS is the lone service insisting on lower-case `key`/`value` tag pairs. */
    public static function ecsTags(array $tags = []): array
    {
        return static::lowerKeyValueTags(static::expectedTags($tags));
    }

    /** Additive — tags YOLO doesn't manage are left alone so manual tags survive sync. */
    public static function tagsRequiringSync(array $expected, array $current): array
    {
        return collect($expected)
            ->filter(fn ($value, $key): bool => ($current[$key] ?? null) !== $value)
            ->all();
    }

    public static function flattenTags(array $tags): array
    {
        if (array_is_list($tags)) {
            return collect($tags)
                ->mapWithKeys(fn (array $tag): array => [
                    ($tag['Key'] ?? $tag['key']) => ($tag['Value'] ?? $tag['value']),
                ])
                ->all();
        }

        return $tags;
    }

    /**
     * Account-scope resources are shared across every environment, so they deliberately
     * carry no `yolo:environment` label (a false attribution and a teardown hazard).
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
     * Single source of truth for tag drift. `$write` receives the current map too, so a
     * full-replace write API (S3 putBucketTagging) can merge without re-reading. The missing
     * tags are always returned so callers can record plan-time Changes.
     *
     * @param  array<string, string>  $expected
     * @param  callable(): array  $read
     * @param  callable(array<string, string>, array<string, string>): mixed  $write
     * @return array<string, string>
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
     * DescribeLogGroups returns the ARN with a trailing `:*` the tagging API rejects.
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
     * @return array<string, string>
     */
    public static function synchroniseServiceDiscoveryTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::serviceDiscovery()->listTagsForResource(['ResourceARN' => $arn])['Tags'] ?? [],
            fn (array $missing) => static::serviceDiscovery()->tagResource([
                'ResourceARN' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * listHostedZones returns the id prefixed (`/hostedzone/Z123`); the tagging API wants it bare.
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
     * @return array<string, string>
     */
    public static function synchroniseElastiCacheTags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::elastiCache()->listTagsForResource(['ResourceName' => $arn])['TagList'] ?? [],
            fn (array $missing) => static::elastiCache()->addTagsToResource([
                'ResourceName' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
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
     * `putBucketTagging` is a full replace, so the write puts back existing + missing.
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
     * @return array<string, string>
     */
    public static function synchroniseWafV2Tags(string $arn, array $tags, bool $apply): array
    {
        return static::reconcileTags(
            $tags,
            fn () => static::wafV2()->listTagsForResource(['ResourceARN' => $arn])['TagInfoForResource']['TagList'] ?? [],
            fn (array $missing) => static::wafV2()->tagResource([
                'ResourceARN' => $arn,
                'Tags' => static::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * Public so a resource doing its own single-read reconcile (HostedZone must inspect live
     * ownership first) can format the write without a second round-trip.
     */
    public static function keyValueTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($value, $key): array => ['Key' => $key, 'Value' => $value])
            ->values()
            ->all();
    }

    protected static function lowerKeyValueTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($value, $key): array => ['key' => $key, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * The SDK's per-waiter defaults are too tight for a cold provision (`ReplicationGroupAvailable`
     * caps at 10 minutes; a fresh Valkey cluster routinely takes longer), so the ceiling is set
     * per call. The before-attempt callback pings WaitReporter so a LongRunning step's progress
     * bar keeps moving instead of freezing.
     *
     * @param  array<string, mixed>  $args
     */
    public static function waitFor(AwsClientInterface $client, string $waiter, array $args, int $timeout = 600, int $interval = 15): void
    {
        $client->waitUntil($waiter, [
            ...$args,
            '@waiter' => [
                'delay' => $interval,
                'maxAttempts' => max(1, (int) ceil($timeout / $interval)),
                'before' => fn ($command, $attempt) => WaitReporter::poll(),
            ],
        ]);
    }

    public static function accountId(): string
    {
        return Manifest::get('account-id');
    }

    public static function profileAccountId(): string
    {
        return static::sts()->getCallerIdentity()['Account'];
    }

    /**
     * Mints a tier-scoped token on top of the developer's profile credentials
     * (get-session-token creds can chain into AssumeRole). MFA serial + TOTP, when supplied,
     * satisfy the admin tier's `aws:MultiFactorAuthPresent` trust condition — a per-assume
     * human factor an agent can't supply.
     *
     * @return array<string, mixed> the Credentials sub-array (AccessKeyId, SecretAccessKey, SessionToken, Expiration)
     */
    public static function assumeRole(string $roleArn, string $sessionName = 'yolo', ?string $mfaSerial = null, ?string $mfaTokenCode = null): array
    {
        $parameters = [
            'RoleArn' => $roleArn,
            'RoleSessionName' => $sessionName,
        ];

        if ($mfaSerial !== null && $mfaTokenCode !== null) {
            $parameters['SerialNumber'] = $mfaSerial;
            $parameters['TokenCode'] = $mfaTokenCode;
        }

        return static::sts()->assumeRole($parameters)['Credentials'];
    }

    /**
     * `sts:GetCallerIdentity` needs no grant. Lets YOLO skip a redundant self-assume when
     * already running as a tier role (the CI/OIDC path).
     */
    public static function callerArn(): string
    {
        return static::sts()->getCallerIdentity()['Arn'] ?? '';
    }

    /**
     * Null when the caller isn't an IAM user or has no device — the operator then sets
     * YOLO_{ENV}_MFA_SERIAL explicitly. Needs iam:ListMFADevices.
     */
    public static function callerMfaSerial(): ?string
    {
        $arn = static::sts()->getCallerIdentity()['Arn'] ?? '';

        if (! preg_match('#:user/(.+)$#', (string) $arn, $matches)) {
            return null;
        }

        $devices = static::iam()->listMFADevices(['UserName' => $matches[1]])['MFADevices'] ?? [];

        return $devices[0]['SerialNumber'] ?? null;
    }

    public static function acm(): AcmClient
    {
        return Helpers::app('acm');
    }

    public static function applicationAutoScaling(): ApplicationAutoScalingClient
    {
        return Helpers::app('applicationAutoScaling');
    }

    public static function cloudFront(): CloudFrontClient
    {
        return Helpers::app('cloudFront');
    }

    public static function costExplorer(): CostExplorerClient
    {
        return Helpers::app('costExplorer');
    }

    public static function cloudWatch(): CloudWatchClient
    {
        return Helpers::app('cloudWatch');
    }

    public static function serviceDiscovery(): ServiceDiscoveryClient
    {
        return Helpers::app('serviceDiscovery');
    }

    public static function cloudWatchLogs(): CloudWatchLogsClient
    {
        return Helpers::app('cloudWatchLogs');
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

    public static function elastiCache(): ElastiCacheClient
    {
        return Helpers::app('elastiCache');
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

    /** us-east-1 is the only region that surfaces global-service resources (IAM, CloudFront, Route 53). */
    public static function resourceGroupsTaggingApiGlobal(): ResourceGroupsTaggingAPIClient
    {
        return Helpers::app('resourceGroupsTaggingApiGlobal');
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

    public static function wafV2(): WAFV2Client
    {
        return Helpers::app('wafV2');
    }
}
