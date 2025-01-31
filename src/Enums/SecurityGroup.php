<?php

namespace Codinglabs\Yolo\Enums;

enum SecurityGroup: string
{
    case EC2_SECURITY_GROUP = 'ec2-security-group';
    case LOAD_BALANCER_SECURITY_GROUP = 'load-balancer-security-group';
    case RDS_SECURITY_GROUP = 'rds-security-group';
}
