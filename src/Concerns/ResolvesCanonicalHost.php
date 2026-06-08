<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

/**
 * The canonical host is whatever `domain` resolves to — the single host an app
 * is served on. When that host is one half of the apex/`www` pair (it is exactly
 * the apex, or exactly `www.{apex}`), the other half is its sibling and should
 * 301-redirect to the canonical host. A host that is any other subdomain has no
 * sibling and is served alone.
 *
 * This is the single source of truth shared by the ALB rules (a forward rule for
 * the canonical host, a redirect rule for the sibling) and the Route 53 records
 * (both halves resolve to the ALB so the redirect rule can catch the sibling).
 */
trait ResolvesCanonicalHost
{
    public function hasWwwSibling(string $apex, string $domain): bool
    {
        return $domain === $apex || $domain === "www.$apex";
    }

    public function wwwSibling(string $apex, string $domain): string
    {
        return $domain === $apex ? "www.$apex" : $apex;
    }
}
