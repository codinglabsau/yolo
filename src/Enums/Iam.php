<?php

namespace Codinglabs\Yolo\Enums;

enum Iam: string
{
    case INSTANCE_PROFILE = 'instance-profile';
    case MEDIA_CONVERT_ROLE = 'mediaconvert-role';
    case ECS_TASK_ROLE = 'ecs-task-role';
    case ECS_TASK_POLICY = 'ecs-task-policy';
}
