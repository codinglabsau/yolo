<?php

namespace Codinglabs\Yolo\Enums;

enum SecurityGroupRule: string
{
    case LOAD_BALANCER_HTTP_RULE = 'load-balancer-http';
    case LOAD_BALANCER_HTTPS_RULE = 'load-balancer-https';
    case ECS_TASK_LB_INGRESS_RULE = 'ecs-task-lb-ingress';
    case RDS_TASK_INGRESS_RULE = 'rds-task-ingress';
}
