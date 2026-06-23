<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\WafV2\AllowIpSet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the WAF allow-list IP set, after the web ACL that referenced it.
 */
class TeardownAllowIpSetStep extends TeardownStep
{
    protected function resource(): AllowIpSet
    {
        return new AllowIpSet();
    }
}
