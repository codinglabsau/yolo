<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\WafV2;

/**
 * The WAF block list — referenced by the Block rule just under the allow list, so
 * a banned IP is dropped before the managed groups even run. This is the lever an
 * operator reaches for to shut down an abusive source. Seeded empty; the operator
 * fills it.
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
