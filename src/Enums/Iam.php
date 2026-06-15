<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

enum Iam: string
{
    case INSTANCE_PROFILE = 'instance-profile';
    case MEDIA_CONVERT_ROLE = 'mediaconvert-role';
    case ECS_TASK_ROLE = 'ecs-task-role';
    case ECS_TASK_POLICY = 'ecs-task-policy';
    case ECS_EXECUTION_ROLE = 'ecs-execution-role';
    case DEPLOYER_ROLE = 'deployer';
    case DEPLOYER_POLICY = 'deployer-policy';
    case OBSERVER_POLICY = 'observer';
}
