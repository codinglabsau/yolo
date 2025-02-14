<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesCodeDeploy
{
    public static function applicationName(): string
    {
        return Helpers::keyedResourceName();
    }

    public static function application(): string
    {
        $applications = Aws::codeDeploy()->listApplications();

        foreach ($applications['applications'] as $application) {
            if ($application === Helpers::keyedResourceName()) {
                return $application;
            }
        }

        throw new ResourceDoesNotExistException(sprintf("Could not find CodeDeploy application %s", Helpers::keyedResourceName()));
    }

    public static function OneThirdAtATimeDeploymentConfig(): array
    {
        $deploymentConfigs = Aws::codeDeploy()->listDeploymentConfigs();

        foreach ($deploymentConfigs['deploymentConfigsList'] as $deploymentConfig) {
            if ($deploymentConfig === 'OneThirdAtATime') {
                return Aws::codeDeploy()->getDeploymentConfig([
                    'deploymentConfigName' => $deploymentConfig,
                ])['deploymentConfigInfo'];
            }
        }

        throw new ResourceDoesNotExistException("Could not find deployment config 'OneThirdAtATime'");
    }

    /** @throws ResourceDoesNotExistException */
    public static function webDeploymentGroup(): array
    {
        return static::deploymentGroup(Helpers::keyedResourceName(ServerGroup::WEB));
    }

    /** @throws ResourceDoesNotExistException */
    public static function queueDeploymentGroup(): array
    {
        return static::deploymentGroup(Helpers::keyedResourceName(ServerGroup::QUEUE));
    }

    /** @throws ResourceDoesNotExistException */
    public static function schedulerDeploymentGroup(): array
    {
        return static::deploymentGroup(Helpers::keyedResourceName(ServerGroup::SCHEDULER));
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
            'serviceRoleArn' => sprintf('arn:aws:iam::%s:role/AWSCodeDeployServiceRole', Aws::accountId()),
        ];
    }

    public static function arnForApplication(string $application): string
    {
        return sprintf(
            'arn:aws:codedeploy:%s:%s:application:%s',
            Manifest::get('aws.region'),
            Aws::accountId(),
            $application
        );
    }

    public static function arnForDeploymentGroup(array $deploymentGroup): string
    {
        return sprintf(
            'arn:aws:codedeploy:%s:%s:deploymentgroup:%s/%s',
            Manifest::get('aws.region'),
            Aws::accountId(),
            $deploymentGroup['applicationName'],
            $deploymentGroup['deploymentGroupName'],
        );
    }

    protected function applyTagsToDeploymentGroup(array $deploymentGroup): void
    {
        Aws::codeDeploy()->tagResource([
            'ResourceArn' => static::arnForDeploymentGroup($deploymentGroup),
            ...Aws::tags([
                'Name' => Helpers::keyedResourceName($deploymentGroup['deploymentGroupName']),
            ]),
        ]);
    }

    public static function normaliseDeploymentGroupForComparison(array $deploymentGroup): array
    {
        return [
            ...$deploymentGroup,
            // flatten autoScalingGroups value as AWS is injecting a name and hook key
            'autoScalingGroups' => [$deploymentGroup['autoScalingGroups'][0]['name'] ?? null],
        ];
    }
}
