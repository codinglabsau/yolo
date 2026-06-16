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
    case ADMIN_POLICY = 'admin';
    case ADMIN_ROLE = 'admin-role';

    // Grant groups — membership is the access lever. Plural by convention (a
    // group holds users), which also keeps the name distinct from the singular
    // role/policy it lets members assume: yolo-{env}[-{app}]-{tier}s.
    case OBSERVERS_GROUP = 'observers';
    case DEPLOYERS_GROUP = 'deployers';
    case ADMINS_GROUP = 'admins';
}
