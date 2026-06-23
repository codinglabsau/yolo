<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;

/**
 * Tears down the internet gateway, detaching it from the VPC first (AWS refuses
 * to delete a gateway still attached). Runs after the subnets and route table.
 */
class TeardownInternetGatewayStep extends TeardownStep
{
    protected function resource(): InternetGateway
    {
        return new InternetGateway();
    }
}
