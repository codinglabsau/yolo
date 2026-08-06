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
    /**
     * Every host YOLO writes an A-alias for — the canonical host, its `www`
     * sibling when it has one, and the wildcard when that party serves its own
     * subdomains. Shared by the record sync and by teardown, so a withdrawal
     * removes exactly what sync wrote and nothing else.
     *
     * `$wildcardHost` is required, and null means "this party has no wildcard".
     * It used to default to `Manifest::wildcardHost()`, which silently wrote the
     * *app's* wildcard into a non-wildcarded tenant's own hosted zone — a party
     * only ever wildcards its own domain, so the caller that knows which party
     * this is has to say.
     *
     * @return array<int, string>
     */
    public function aliasedHosts(string $apex, string $domain, ?string $wildcardHost): array
    {
        return array_values(array_filter([
            $domain,
            $this->hasWwwSibling($apex, $domain) ? $this->wwwSibling($apex, $domain) : null,
            $wildcardHost,
        ]));
    }

    public function hasWwwSibling(string $apex, string $domain): bool
    {
        return $domain === $apex || $domain === "www.$apex";
    }

    public function wwwSibling(string $apex, string $domain): string
    {
        return $domain === $apex ? "www.$apex" : $apex;
    }
}
