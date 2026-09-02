<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;

class TeardownInternetGatewayStep extends TeardownStep
{
    protected function resource(): InternetGateway
    {
        return new InternetGateway();
    }
}
