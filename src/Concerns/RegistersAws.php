<?php

namespace Codinglabs\Yolo\Concerns;

use Aws\S3\S3Client;
use Aws\Acm\AcmClient;
use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Aws\Sts\StsClient;
use GuzzleHttp\Client;
use Aws\Ssm\SsmClient;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Route53\Route53Client;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\Credentials\CredentialProvider;
use GuzzleHttp\Exception\ConnectException;
use Aws\ElasticTranscoder\ElasticTranscoderClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

trait RegistersAws
{
    protected function registerAwsServices(): void
    {
        // common arguments for all AWS clients
        $arguments = [
            'region' => Manifest::get('aws.region'),
            'version' => 'latest',
            'credentials' => static::awsCredentials(),
        ];

        // register all required AWS clients
        Helpers::app()->singleton('acm', fn () => new AcmClient($arguments));
        Helpers::app()->singleton('autoscaling', fn () => new AutoScalingClient($arguments));
        Helpers::app()->singleton('codeDeploy', fn () => new CodeDeployClient($arguments));
        Helpers::app()->singleton('cloudWatch', fn () => new CloudWatchClient($arguments));
        Helpers::app()->singleton('ec2', fn () => new Ec2Client($arguments));
        Helpers::app()->singleton('elasticLoadBalancingV2', fn () => new ElasticLoadBalancingV2Client($arguments));
        Helpers::app()->singleton('elasticTranscoder', fn () => new ElasticTranscoderClient($arguments));
        Helpers::app()->singleton('rds', fn () => new RdsClient($arguments));
        Helpers::app()->singleton('route53', fn () => new Route53Client($arguments));
        Helpers::app()->singleton('s3', fn () => new S3Client($arguments));
        Helpers::app()->singleton('sns', fn () => new SnsClient($arguments));
        Helpers::app()->singleton('sqs', fn () => new SqsClient($arguments));
        Helpers::app()->singleton('ssm', fn () => new SsmClient($arguments));
        Helpers::app()->singleton('sts', fn () => new StsClient($arguments));

        // with all clients registered, we can now determine specific environments
        Helpers::app()->singleton('runningInAwsWebEnvironment', fn () => static::detectAwsWebEnvironment());
        Helpers::app()->singleton('runningInAwsQueueEnvironment', fn () => static::detectAwsQueueEnvironment());
        Helpers::app()->singleton('runningInAwsSchedulerEnvironment', fn () => static::detectAwsSchedulerEnvironment());
    }

    protected static function awsCredentials(): callable|array|null
    {
        if (Aws::runningInAws()) {
            // On AWS we are using IAM roles, so we don't need to provide credentials
            return null;
        }

        // in CI (GitHub Actions) we use environment variables, otherwise we
        // are using a local env value to point to the correct AWS profile.
        return static::detectCiEnvironment()
            ? [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
            : CredentialProvider::ini(Helpers::keyedEnv('AWS_PROFILE'));
    }

    protected static function detectLocalEnvironment(): bool
    {
        return env('APP_ENV', false) === 'local';
    }

    protected static function detectCiEnvironment(): bool
    {
        return env('CI', false) === true;
    }

    protected static function detectAwsEnvironment(string $name = null): bool
    {
        if (static::detectLocalEnvironment() || static::detectCiEnvironment()) {
            // skip if we are local or in continuous integration
            return false;
        }

        try {
            $instanceId = (new Client(['timeout' => 2]))
                ->get('http://169.254.169.254/latest/meta-data/instance-id')
                ->getBody();

            if ($name) {
                $awsResult = Aws::ec2()->describeTags([
                    'Filters' => [
                        [
                            'Name' => 'resource-id',
                            'Values' => [$instanceId],
                        ],
                        [
                            'Name' => 'key',
                            'Values' => ['Name'],
                        ],
                    ]
                ]);

                return ! empty($awsResult['Tags']) && $awsResult['Tags'][0]['Value'] === $name;
            }

            return true;
        } catch (ConnectException $e) {
        }

        return false;
    }

    protected static function detectAwsWebEnvironment(): bool
    {
        return static::detectAwsEnvironment('web');
    }

    protected static function detectAwsQueueEnvironment(): bool
    {
        return static::detectAwsEnvironment('queue');
    }

    protected static function detectAwsSchedulerEnvironment(): bool
    {
        return static::detectAwsEnvironment('scheduler');
    }
}
