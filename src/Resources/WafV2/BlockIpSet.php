<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\WafV2;

/**
 * Evaluated before the managed groups — the operator's lever against an abusive
 * source. Seeded empty; operator-managed.
 */
class BlockIpSet extends IpSet
{
    public function name(): string
    {
        return $this->keyedName('waf-block');
    }

    protected function description(): string
    {
        return 'YOLO WAF block list - operator-managed, never reconciled';
    }
}
