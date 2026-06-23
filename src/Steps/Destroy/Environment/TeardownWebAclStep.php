<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the env WAF web ACL. It's disassociated from the load balancer
 * earlier (DisassociateWafStep) — WAFv2 refuses to delete an associated ACL.
 */
class TeardownWebAclStep extends TeardownStep
{
    protected function resource(): WebAcl
    {
        return new WebAcl();
    }
}
