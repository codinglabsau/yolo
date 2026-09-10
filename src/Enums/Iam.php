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
    case OBSERVER_ROLE = 'observer-role';
    case DATA_READ_POLICY = 'data-read';
    case DATA_WRITE_POLICY = 'data-write';
    case DEVELOPER_ROLE = 'developer-role';
    case ADMIN_POLICY = 'admin';
    case ADMIN_ROLE = 'admin-role';

    // Grant groups are plural so the name stays distinct from the singular role/policy they assume.
    case OBSERVERS_GROUP = 'observers';
    case DEVELOPERS_GROUP = 'developers';
    case ADMINS_GROUP = 'admins';
}
