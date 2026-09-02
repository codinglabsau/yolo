<?php

namespace Codinglabs\Yolo\Concerns;

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
use Codinglabs\Yolo\Aws;
use Aws\WAFV2\WAFV2Client;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Route53\Route53Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\ElastiCache\ElastiCacheClient;
use Aws\EventBridge\EventBridgeClient;
use Aws\Credentials\CredentialProvider;
use Aws\CostExplorer\CostExplorerClient;
use Aws\Credentials\CredentialsInterface;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\ServiceDiscovery\ServiceDiscoveryClient;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Aws\ApplicationAutoScaling\ApplicationAutoScalingClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

trait RegistersAws
{
    /**
     * Every client a forked plan worker must release; a test pins it against the
     * actual registrations.
     *
     * @var array<int, string>
     */
    public const AWS_CLIENT_BINDINGS = [
        'acm',
        'applicationAutoScaling',
        'cloudWatch',
        'cloudWatchLogs',
        'cloudFront',
        'costExplorer',
        'ec2',
        'elastiCache',
        'ecr',
        'ecs',
        'eventBridge',
        'elasticLoadBalancingV2',
        'iam',
        'rds',
        'resourceGroupsTaggingApi',
        'resourceGroupsTaggingApiGlobal',
        'route53',
        's3',
        'serviceDiscovery',
        'sns',
        'sqs',
        'sts',
        'wafV2',
    ];

    /**
     * A forked child inherits the parent's resolved clients and with them its open
     * curl sockets, which two processes must never share. The singleton bindings
     * survive, so clients rebuild lazily in the child.
     */
    public static function forgetAwsClients(): void
    {
        foreach (self::AWS_CLIENT_BINDINGS as $service) {
            Helpers::app()->forgetInstance($service);
        }
    }

    /**
     * The SDK ships no request timeout, so one stalled response would wedge a plan
     * worker forever; a timeout is retryable under standard mode, so a flaky read
     * costs a retry, not a hung sync. S3 gets longer because it moves real payloads.
     *
     * @return array<string, mixed>
     */
    protected static function awsClientArguments(): array
    {
        return [
            'region' => Manifest::get('region'),
            'version' => 'latest',
            'credentials' => static::awsCredentials(),
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'retries' => ['mode' => 'standard', 'max_attempts' => 3],
        ];
    }

    protected function registerAwsServices(): void
    {
        $arguments = static::awsClientArguments();

        Helpers::app()->singleton('acm', fn (): AcmClient => new AcmClient($arguments));
        Helpers::app()->singleton('applicationAutoScaling', fn (): ApplicationAutoScalingClient => new ApplicationAutoScalingClient($arguments));
        Helpers::app()->singleton('cloudWatch', fn (): CloudWatchClient => new CloudWatchClient($arguments));
        Helpers::app()->singleton('cloudWatchLogs', fn (): CloudWatchLogsClient => new CloudWatchLogsClient($arguments));
        // CloudFront and Cost Explorer are global services — their APIs only live in us-east-1.
        Helpers::app()->singleton('cloudFront', fn (): CloudFrontClient => new CloudFrontClient([...$arguments, 'region' => 'us-east-1']));
        Helpers::app()->singleton('costExplorer', fn (): CostExplorerClient => new CostExplorerClient([...$arguments, 'region' => 'us-east-1']));
        Helpers::app()->singleton('ec2', fn (): Ec2Client => new Ec2Client($arguments));
        Helpers::app()->singleton('elastiCache', fn (): ElastiCacheClient => new ElastiCacheClient($arguments));
        Helpers::app()->singleton('ecr', fn (): EcrClient => new EcrClient($arguments));
        Helpers::app()->singleton('ecs', fn (): EcsClient => new EcsClient($arguments));
        Helpers::app()->singleton('eventBridge', fn (): EventBridgeClient => new EventBridgeClient($arguments));
        Helpers::app()->singleton('elasticLoadBalancingV2', fn (): ElasticLoadBalancingV2Client => new ElasticLoadBalancingV2Client($arguments));
        Helpers::app()->singleton('iam', fn (): IamClient => new IamClient($arguments));
        Helpers::app()->singleton('rds', fn (): RdsClient => new RdsClient($arguments));
        Helpers::app()->singleton('resourceGroupsTaggingApi', fn (): ResourceGroupsTaggingAPIClient => new ResourceGroupsTaggingAPIClient($arguments));
        // Global-service resources (IAM, CloudFront, Route 53) are only returned by a us-east-1 query.
        Helpers::app()->singleton('resourceGroupsTaggingApiGlobal', fn (): ResourceGroupsTaggingAPIClient => new ResourceGroupsTaggingAPIClient([...$arguments, 'region' => 'us-east-1']));
        Helpers::app()->singleton('route53', fn (): Route53Client => new Route53Client($arguments));
        // A single-part asset upload can be ≤16MB (the Transfer manager's multipart
        // threshold), so the control-plane timeout would kill uploads on a slow uplink.
        Helpers::app()->singleton('s3', fn (): S3Client => new S3Client([...$arguments, 'http' => ['connect_timeout' => 5, 'timeout' => 120]]));
        Helpers::app()->singleton('serviceDiscovery', fn (): ServiceDiscoveryClient => new ServiceDiscoveryClient($arguments));
        Helpers::app()->singleton('sns', fn (): SnsClient => new SnsClient($arguments));
        Helpers::app()->singleton('sqs', fn (): SqsClient => new SqsClient($arguments));
        Helpers::app()->singleton('sts', fn (): StsClient => new StsClient($arguments));
        Helpers::app()->singleton('wafV2', fn (): WAFV2Client => new WAFV2Client($arguments));
    }

    protected static function awsCredentials(): CredentialsInterface|callable|array|null
    {
        // Set once mintTierCredentials has minted a scoped tier token; caps the run to the tier's policy.
        if (Helpers::app()->bound('yoloAssumedCredentials')) {
            return Helpers::app('yoloAssumedCredentials');
        }

        if (Aws::runningInAws()) {
            // Task IAM role via the SDK default chain.
            return null;
        }

        // CI defers to the SDK default chain too — it resolves whatever the runner
        // provides (GitHub OIDC, SSO) with no manifest changes.
        if (static::detectCiEnvironment()) {
            return null;
        }

        $profile = Helpers::keyedEnv('AWS_PROFILE');

        if (in_array($profile, ['', null, 'default'])) {
            throw new IntegrityCheckException(sprintf('Using the default AWS profile in your credentials file is risky. Name your profile to something specific and update %s in your .env file before proceeding.', Helpers::keyedEnvName('AWS_PROFILE')));
        }

        // Both the credentials and config files, so a `credential_process` profile
        // resolves from wherever it's defined. Built explicitly rather than via
        // defaultProvider(), which only reads $AWS_PROFILE — keeps the profile scoped
        // without mutating the environment.
        $configFile = CredentialProvider::getConfigFileName();

        return CredentialProvider::memoize(
            CredentialProvider::chain(
                CredentialProvider::process($profile),
                CredentialProvider::ini($profile),
                CredentialProvider::process('profile ' . $profile, $configFile),
                CredentialProvider::ini('profile ' . $profile, $configFile),
            )
        );
    }

    /**
     * The account guard still STS-verifies whatever creds resolve, so not requiring
     * a profile on AWS / in CI doesn't weaken the which-account safety net.
     */
    protected static function requiresAwsProfile(): bool
    {
        return ! Aws::runningInAws() && ! static::detectCiEnvironment();
    }

    protected static function detectCiEnvironment(): bool
    {
        return env('CI', false) === true;
    }

    /**
     * ECS injects ECS_CONTAINER_METADATA_URI_V4 into every task; an EC2
     * instance-metadata probe would silently read false on Fargate.
     */
    protected static function detectAwsEnvironment(): bool
    {
        return getenv('ECS_CONTAINER_METADATA_URI_V4') !== false;
    }
}
