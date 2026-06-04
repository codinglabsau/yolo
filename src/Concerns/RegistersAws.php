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
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use GuzzleHttp\Client;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Route53\Route53Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\ElastiCache\ElastiCacheClient;
use Aws\EventBridge\EventBridgeClient;
use Aws\Credentials\CredentialProvider;
use GuzzleHttp\Exception\ConnectException;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Aws\ApplicationAutoScaling\ApplicationAutoScalingClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;

trait RegistersAws
{
    protected function registerAwsServices(): void
    {
        // common arguments for all AWS clients
        $arguments = [
            'region' => Manifest::get('region'),
            'version' => 'latest',
            'credentials' => static::awsCredentials(),
        ];

        // register all required AWS clients
        Helpers::app()->singleton('acm', fn () => new AcmClient($arguments));
        Helpers::app()->singleton('applicationAutoScaling', fn () => new ApplicationAutoScalingClient($arguments));
        Helpers::app()->singleton('codeDeploy', fn () => new CodeDeployClient($arguments));
        Helpers::app()->singleton('cloudWatch', fn () => new CloudWatchClient($arguments));
        Helpers::app()->singleton('cloudWatchLogs', fn () => new CloudWatchLogsClient($arguments));
        // CloudFront is a global service — its control-plane API only lives in us-east-1.
        Helpers::app()->singleton('cloudFront', fn () => new CloudFrontClient([...$arguments, 'region' => 'us-east-1']));
        Helpers::app()->singleton('ec2', fn () => new Ec2Client($arguments));
        Helpers::app()->singleton('elastiCache', fn () => new ElastiCacheClient($arguments));
        Helpers::app()->singleton('ecr', fn () => new EcrClient($arguments));
        Helpers::app()->singleton('ecs', fn () => new EcsClient($arguments));
        Helpers::app()->singleton('eventBridge', fn () => new EventBridgeClient($arguments));
        Helpers::app()->singleton('elasticLoadBalancingV2', fn () => new ElasticLoadBalancingV2Client($arguments));
        Helpers::app()->singleton('iam', fn () => new IamClient($arguments));
        Helpers::app()->singleton('rds', fn () => new RdsClient($arguments));
        Helpers::app()->singleton('resourceGroupsTaggingApi', fn () => new ResourceGroupsTaggingAPIClient($arguments));
        Helpers::app()->singleton('route53', fn () => new Route53Client($arguments));
        Helpers::app()->singleton('s3', fn () => new S3Client($arguments));
        Helpers::app()->singleton('sns', fn () => new SnsClient($arguments));
        Helpers::app()->singleton('sqs', fn () => new SqsClient($arguments));
        Helpers::app()->singleton('ssm', fn () => new SsmClient($arguments));
        Helpers::app()->singleton('sts', fn () => new StsClient($arguments));
    }

    protected static function awsCredentials(): callable|array|null
    {
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

    protected static function detectLocalEnvironment(): bool
    {
        return env('APP_ENV', false) === 'local';
    }

    protected static function detectCiEnvironment(): bool
    {
        return env('CI', false) === true;
    }

    protected static function detectAwsEnvironment(): bool
    {
        if (static::detectLocalEnvironment() || static::detectCiEnvironment()) {
            // skip if we are local or in continuous integration
            return false;
        }

        try {
            (new Client(['timeout' => 2]))
                ->get('http://169.254.169.254/latest/meta-data/instance-id');

            return true;
        } catch (ConnectException $e) {
        }

        return false;
    }
}
