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
 * Deliberately NOT a SynchronisesConfiguration: the addresses are the human-owned
 * surface (IPs blocked mid-incident, crawler ranges allowed), so sync only ever
 * creates the set and reconciles its tags — an operator's console edits survive
 * every sync. Seeded empty: an empty IP-set rule matches nothing.
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
     * WAFv2 refuses to delete an IP set a rule still references; the destroy
     * order deletes the WebAcl first, so a plain deleteIPSet succeeds here.
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
