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
     * Every container key registerAwsServices() binds — the full client set a
     * forked plan worker must release so each child process lazily constructs
     * its own clients instead of inheriting the parent's. Pinned against the
     * actual registrations by a test, so adding a client without listing it
     * here fails fast.
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
     * Drop every resolved AWS client instance. A forked child inherits the
     * parent's resolved clients — and with them the parent's open curl
     * sockets, which two processes must never share — so each plan worker
     * calls this first. The singleton bindings survive, so clients rebuild
     * lazily in the child with the same arguments (including the memoised
     * credentials, which are plain values by that point).
     */
    public static function forgetAwsClients(): void
    {
        foreach (self::AWS_CLIENT_BINDINGS as $service) {
            Helpers::app()->forgetInstance($service);
        }
    }

    /**
     * The arguments every AWS client is constructed with. The SDK ships no
     * request timeout, so one stalled response would wedge a plan worker
     * forever. A timeout surfaces as a connection error, which standard-mode
     * retries treat as retryable — so a flaky read costs a backoff-and-retry,
     * not a hung sync. 60s per request also holds for S3: the asset push goes
     * through the Transfer manager, which chunks anything large into multipart.
     *
     * @return array<string, mixed>
     */
    protected static function awsClientArguments(): array
    {
        return [
            'region' => Manifest::get('region'),
            'version' => 'latest',
            'credentials' => static::awsCredentials(),
            'http' => ['connect_timeout' => 5, 'timeout' => 60],
            'retries' => ['mode' => 'standard', 'max_attempts' => 4],
        ];
    }

    protected function registerAwsServices(): void
    {
        $arguments = static::awsClientArguments();

        // register all required AWS clients
        Helpers::app()->singleton('acm', fn (): AcmClient => new AcmClient($arguments));
        Helpers::app()->singleton('applicationAutoScaling', fn (): ApplicationAutoScalingClient => new ApplicationAutoScalingClient($arguments));
        Helpers::app()->singleton('cloudWatch', fn (): CloudWatchClient => new CloudWatchClient($arguments));
        Helpers::app()->singleton('cloudWatchLogs', fn (): CloudWatchLogsClient => new CloudWatchLogsClient($arguments));
        // CloudFront is a global service — its control-plane API only lives in us-east-1.
        Helpers::app()->singleton('cloudFront', fn (): CloudFrontClient => new CloudFrontClient([...$arguments, 'region' => 'us-east-1']));
        // Cost Explorer is a global service — its API only lives in us-east-1.
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
        // The Tagging API is regional; global-service resources (IAM, CloudFront, Route 53) are only
        // returned by a us-east-1 query, so the audit needs a second client pinned there to see them.
        Helpers::app()->singleton('resourceGroupsTaggingApiGlobal', fn (): ResourceGroupsTaggingAPIClient => new ResourceGroupsTaggingAPIClient([...$arguments, 'region' => 'us-east-1']));
        Helpers::app()->singleton('route53', fn (): Route53Client => new Route53Client($arguments));
        Helpers::app()->singleton('s3', fn (): S3Client => new S3Client($arguments));
        Helpers::app()->singleton('serviceDiscovery', fn (): ServiceDiscoveryClient => new ServiceDiscoveryClient($arguments));
        Helpers::app()->singleton('sns', fn (): SnsClient => new SnsClient($arguments));
        Helpers::app()->singleton('sqs', fn (): SqsClient => new SqsClient($arguments));
        Helpers::app()->singleton('sts', fn (): StsClient => new StsClient($arguments));
        Helpers::app()->singleton('wafV2', fn (): WAFV2Client => new WAFV2Client($arguments));
    }

    protected static function awsCredentials(): CredentialsInterface|callable|array|null
    {
        // Once YOLO has minted a scoped tier token (mintTierCredentials), every
        // client re-registers against those assumed-role credentials, capping the
        // run to the tier's policy. Until then this binding is unset and the normal
        // profile/CI/task-role resolution below applies.
        if (Helpers::app()->bound('yoloAssumedCredentials')) {
            return Helpers::app('yoloAssumedCredentials');
        }

        if (Aws::runningInAws()) {
            // On AWS we use the instance/task IAM role — defer to the SDK default
            // credential chain (IMDS / container credentials).
            return null;
        }

        // In CI we defer to the SDK default credential chain too, so it works out
        // of the box with no manifest changes — the chain resolves whatever the
        // runner provides: GitHub OIDC via aws-actions/configure-aws-credentials
        // (the keyless path) or AWS IAM Identity Center (SSO).
        if (static::detectCiEnvironment()) {
            return null;
        }

        // otherwise we are using a local env value to point to the correct AWS profile.
        $profile = Helpers::keyedEnv('AWS_PROFILE');

        if (in_array($profile, ['', null, 'default'])) {
            throw new IntegrityCheckException(sprintf('Using the default AWS profile in your credentials file is risky. Name your profile to something specific and update %s in your .env file before proceeding.', Helpers::keyedEnvName('AWS_PROFILE')));
        }

        // Resolve the named profile from both the credentials and config files, so
        // a `credential_process` profile (e.g. 1Password-backed short-lived creds)
        // resolves from wherever it's defined. Built explicitly rather than via
        // defaultProvider() — which only reads the profile from $AWS_PROFILE — so
        // the profile stays scoped without mutating the environment. Memoised so
        // credentials resolve once per run.
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
     * A named AWS profile is required only for genuinely local runs (off AWS and
     * outside CI). On AWS we use the task role; in CI awsCredentials() defers to
     * the SDK default chain (OIDC / SSO), so the profile is never consulted. The
     * account guard still STS-verifies whatever creds resolve, so not requiring a
     * profile here doesn't weaken the which-account safety net.
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
     * Whether we're running inside a deployed ECS container. ECS injects
     * ECS_CONTAINER_METADATA_URI_V4 into every task (Fargate and EC2 launch types
     * alike), so its presence is an exact, instant signal — where the old EC2
     * instance-metadata probe (169.254.169.254) silently read false on Fargate,
     * which doesn't expose it. Drives both the credential strategy (task role vs
     * named profile) and the base command's hard refusal to run in-container.
     */
    protected static function detectAwsEnvironment(): bool
    {
        return getenv('ECS_CONTAINER_METADATA_URI_V4') !== false;
    }
}
