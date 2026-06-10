<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

enum SecurityGroup: string
{
    case ECS_TASK_SECURITY_GROUP = 'ecs-task-security-group';
    case LOAD_BALANCER_SECURITY_GROUP = 'load-balancer-security-group';
    case RDS_SECURITY_GROUP = 'rds-security-group';
    case CACHE_SECURITY_GROUP = 'cache-security-group';
    case MEILISEARCH_SECURITY_GROUP = 'meilisearch-security-group';
}
