<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\WafV2;

/**
 * The WAF allow list — referenced by the top-priority Allow rule so anything in
 * it bypasses the managed groups below (the place to put known-good crawler
 * ranges that a managed group might otherwise false-positive). Seeded empty;
 * the operator fills it.
 */
class AllowIpSet extends IpSet
{
    public function name(): string
    {
        return $this->keyedName('waf-allow');
    }

    protected function description(): string
    {
        return 'YOLO WAF allow list - known-good IPs, operator-managed, never reconciled';
    }
}
