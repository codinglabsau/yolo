<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

/**
 * When `domain` is one half of the apex/`www` pair the other half is its sibling
 * and 301-redirects to it; any other subdomain has no sibling. Single source of
 * truth for the ALB rules (forward + redirect) and the Route 53 records, so both
 * halves resolve to the ALB for the redirect rule to catch.
 */
trait ResolvesCanonicalHost
{
    /**
     * Shared by the record sync and by teardown, so a withdrawal removes exactly
     * what sync wrote. `$wildcardHost` has no default: a wildcard only ever covers
     * its own domain, so the caller must say which one it is resolving.
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
