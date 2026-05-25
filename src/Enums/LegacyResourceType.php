<?php

namespace Codinglabs\Yolo\Enums;

use Codinglabs\Yolo\Audit\Arn;

/**
 * The alpha-era (EC2 / ASG / CodeDeploy) resource types the legacy audit reports.
 * The Fargate stack never provisions the compute primitives below, so finding any
 * still tagged means the legacy stack hasn't been fully torn down.
 */
enum LegacyResourceType: string
{
    case AutoScalingGroup = 'auto-scaling-group';
    case Ec2Instance = 'ec2-instance';
    case LaunchTemplate = 'launch-template';
    case KeyPair = 'key-pair';
    case CodeDeployApplication = 'codedeploy-application';
    case CodeDeployDeploymentGroup = 'codedeploy-deployment-group';
    case TargetGroup = 'target-group';
    case LoadBalancer = 'load-balancer';

    /**
     * The legacy resource an ARN represents, or null when it isn't part of the
     * alpha stack — i.e. a current Fargate resource or shared infrastructure that
     * survives the cutover.
     */
    public static function tryFromArn(string $arn): ?self
    {
        $parsed = Arn::parse($arn);

        if ($parsed === null) {
            return null;
        }

        return match ([$parsed->service, $parsed->resourceType]) {
            ['autoscaling', 'autoScalingGroup'] => self::AutoScalingGroup,
            ['ec2', 'instance'] => self::Ec2Instance,
            ['ec2', 'launch-template'] => self::LaunchTemplate,
            ['ec2', 'key-pair'] => self::KeyPair,
            ['codedeploy', 'application'] => self::CodeDeployApplication,
            ['codedeploy', 'deploymentgroup'] => self::CodeDeployDeploymentGroup,
            ['elasticloadbalancing', 'targetgroup'] => self::TargetGroup,
            ['elasticloadbalancing', 'loadbalancer'] => self::LoadBalancer,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AutoScalingGroup => 'Auto Scaling group',
            self::Ec2Instance => 'EC2 instance',
            self::LaunchTemplate => 'Launch template',
            self::KeyPair => 'Key pair',
            self::CodeDeployApplication => 'CodeDeploy application',
            self::CodeDeployDeploymentGroup => 'CodeDeploy deployment group',
            self::TargetGroup => 'Target group',
            self::LoadBalancer => 'Load balancer',
        };
    }

    /**
     * ELBv2 resources exist on both stacks, so they're only legacy when their
     * Name isn't one the current deploy owns. The compute primitives are
     * structurally absent from Fargate, so they're always legacy.
     */
    public function isShared(): bool
    {
        return match ($this) {
            self::TargetGroup, self::LoadBalancer => true,
            default => false,
        };
    }
}
