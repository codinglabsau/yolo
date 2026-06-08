<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

enum ElastiCache: string
{
    case CACHE_CLUSTER = 'cache';
    case CACHE_SUBNET_GROUP = 'cache-subnet-group';
    case CACHE_PARAMETER_GROUP = 'cache-parameter-group';
}
