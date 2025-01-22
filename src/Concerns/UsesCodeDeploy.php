<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesCodeDeploy
{
    protected static string $application;
    protected static array $oneThirdAtATimeDeploymentConfig;
    protected static array $webDeploymentGroup;
    protected static array $queueDeploymentGroup;
    protected static array $schedulerDeploymentGroup;

    public static function applicationName(): string
    {
        return Helpers::keyedResourceName(Manifest::name());
    }

    public static function application(): string
    {
        if (isset(static::$application)) {
            return static::$application;
        }

        $applications = Aws::codeDeploy()->listApplications();

        foreach ($applications['applications'] as $application) {
            if ($application === Helpers::keyedResourceName(Manifest::name())) {
                return static::$application = $application;
            }
        }

        throw new ResourceDoesNotExistException(sprintf("Could not find CodeDeploy application %s", Helpers::keyedResourceName(Manifest::name())));
    }

    public static function OneThirdAtATimeDeploymentConfig(): array
    {
        if (isset(static::$oneThirdAtATimeDeploymentConfig)) {
            return static::$oneThirdAtATimeDeploymentConfig;
        }

        $deploymentConfigs = Aws::codeDeploy()->listDeploymentConfigs();

        foreach ($deploymentConfigs['deploymentConfigsList'] as $deploymentConfig) {
            if ($deploymentConfig === 'OneThirdAtATime') {
                return static::$oneThirdAtATimeDeploymentConfig = Aws::codeDeploy()->getDeploymentConfig([
                    'deploymentConfigName' => $deploymentConfig,
                ])['deploymentConfigInfo'];
            }
        }

        throw new ResourceDoesNotExistException("Could not find deployment config 'OneThirdAtATime'");
    }

    /** @throws ResourceDoesNotExistException */
    public static function webDeploymentGroup(): array
    {
        if (isset(static::$webDeploymentGroup)) {
            return static::$webDeploymentGroup;
        }

        return static::deploymentGroup(Helpers::keyedResourceName('web'));
    }

    /** @throws ResourceDoesNotExistException */
    public static function queueDeploymentGroup(): array
    {
        if (isset(static::$queueDeploymentGroup)) {
            return static::$queueDeploymentGroup;
        }

        return static::deploymentGroup(Helpers::keyedResourceName('queue'));
    }

    /** @throws ResourceDoesNotExistException */
    public static function schedulerDeploymentGroup(): array
    {
        if (isset(static::$schedulerDeploymentGroup)) {
            return static::$schedulerDeploymentGroup;
        }

        return static::deploymentGroup(Helpers::keyedResourceName('scheduler'));
    }

    protected static function deploymentGroup(string $name): array
    {
        $deploymentGroups = Aws::codeDeploy()->listDeploymentGroups([
            'applicationName' => static::application(),
        ]);

        foreach ($deploymentGroups['deploymentGroups'] as $deploymentGroup) {
            if ($deploymentGroup === $name) {
                return Aws::codeDeploy()->batchGetDeploymentGroups([
                    'applicationName' => static::application(),
                    'deploymentGroupNames' => [$name],
                ])['deploymentGroupsInfo'][0];
            }
        }

        throw new ResourceDoesNotExistException(sprintf("Could not find deployment group %s", $name));
    }

    public static function deploymentGroupPayload(): array
    {
        return [
            'applicationName' => AwsResources::application(),
            'outdatedInstancesStrategy' => 'UPDATE',
            'serviceRoleArn' => sprintf('arn:aws:iam::%s:role/AWSCodeDeployServiceRole', Manifest::get('aws.account-id')),
        ];
    }
}
