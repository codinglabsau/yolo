<?php

namespace Codinglabs\Yolo\Resources\WafV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * An env-shared WAF IP set referenced by the WebAcl's allow/block rules.
 *
 * Deliberately NOT a SynchronisesConfiguration: the addresses are the high-churn,
 * human-owned surface (the IPs you block at 2am, the crawler ranges you allow), so
 * sync only ever creates the set and reconciles its tags — it never rewrites the
 * contents. An operator can edit the list in the console mid-incident and the next
 * `sync` leaves it untouched. YOLO owns that the set *exists* and that a rule wires
 * it into the ACL; the operator owns what's *in* it. The set is seeded empty (an
 * empty IP-set rule matches nothing, so it's inert until populated).
 */
abstract class IpSet implements Deletable, Resource
{
    use ResolvesTags;

    abstract public function name(): string;

    abstract protected function description(): string;

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            WafV2::ipSet($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return WafV2::ipSet($this->name())['ARN'];
    }

    public function create(): void
    {
        Aws::wafV2()->createIPSet([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Description' => $this->description(),
            'IPAddressVersion' => 'IPV4',
            'Addresses' => [],
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseWafV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown when the environment is torn down: delete the IP set. WAFv2 needs
     * the current LockToken (optimistic concurrency) and the Id, both read from
     * the live summary. The destroy step deletes the WebAcl that references this
     * set first — WAFv2 refuses to delete an IP set still referenced by a rule —
     * so by the time we get here a plain deleteIPSet succeeds. A concurrent
     * removal (the summary lookup already 404s) is tolerated.
     */
    public function delete(): void
    {
        try {
            $summary = WafV2::ipSet($this->name());
        } catch (ResourceDoesNotExistException) {
            return;
        }

        Aws::wafV2()->deleteIPSet([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
            'LockToken' => $summary['LockToken'],
        ]);
    }
}
