<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Forwards the app's search host to the shared Meilisearch target group. The
 * host is derived, not declared: `search.{apex}` — capability-named (a future
 * engine swap doesn't change the host) and always covered by the app's existing
 * `{apex}` + `*.{apex}` SAN certificate, so no new cert is ever issued for it.
 * The compute behind it is env-shared; this rule (and the matching Route 53
 * record) is the app-scoped ingress, additively attached like the app's own
 * forward rule.
 */
class SearchListenerRule extends ForwardListenerRule
{
    /**
     * The one derived search host — shared by this rule, the Route 53 record
     * and the MEILISEARCH_HOST env injection, so the derivation can't fork.
     */
    public static function host(): string
    {
        return sprintf('search.%s', Manifest::apex());
    }

    #[\Override]
    public function name(): string
    {
        return $this->keyedName('search');
    }

    #[\Override]
    public function hosts(): array
    {
        return [static::host()];
    }

    #[\Override]
    protected function targetGroup(): Resource
    {
        return new MeilisearchTargetGroup();
    }
}
