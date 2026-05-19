<?php

namespace Codinglabs\Yolo\Enums;

enum SecurityGroupRule: string
{
    case LOAD_BALANCER_HTTP_RULE = 'load-balancer-http';
    case LOAD_BALANCER_HTTPS_RULE = 'load-balancer-https';
    case LOAD_BALANCER_INGRESS_RULE = 'load-balancer-ingress';
    case ECS_TASK_LB_INGRESS_RULE = 'ecs-task-lb-ingress';
    case SSH_INGRESS_RULE = 'ssh-ingress';
}
