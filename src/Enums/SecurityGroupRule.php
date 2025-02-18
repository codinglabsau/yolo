<?php

namespace Codinglabs\Yolo\Enums;

enum SecurityGroupRule: string
{
    case LOAD_BALANCER_INGRESS_RULE = 'load-balancer-ingress';
    case SSH_INGRESS_RULE = 'ssh-ingress';
}
