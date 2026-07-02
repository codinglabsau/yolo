<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

enum PrivateSubnets: string
{
    case PRIVATE_SUBNET_A = 'private-subnet-a';
    case PRIVATE_SUBNET_B = 'private-subnet-b';
    case PRIVATE_SUBNET_C = 'private-subnet-c';
}
