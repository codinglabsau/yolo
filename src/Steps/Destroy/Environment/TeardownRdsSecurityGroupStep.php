<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;

/**
 * Tears down the RDS security group, after any database that used it is gone
 * (AWS refuses to delete a security group still in use).
 */
class TeardownRdsSecurityGroupStep extends TeardownStep
{
    protected function resource(): RdsSecurityGroup
    {
        return new RdsSecurityGroup();
    }
}
