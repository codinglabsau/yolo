<?php

namespace Codinglabs\Yolo\Enums;

enum SecurityGroupRule: string
{
    case LOAD_BALANCER_HTTP_RULE = 'load-balancer-http';
    case LOAD_BALANCER_HTTPS_RULE = 'load-balancer-https';
    case LOAD_BALANCER_INGRESS_RULE = 'load-balancer-ingress';
    case SSH_INGRESS_RULE = 'ssh-ingress';
}
