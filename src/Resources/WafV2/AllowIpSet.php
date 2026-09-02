<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\WafV2;

/**
 * Bypasses the managed groups — the place for known-good crawler ranges a
 * managed group would false-positive. Seeded empty; operator-managed.
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
