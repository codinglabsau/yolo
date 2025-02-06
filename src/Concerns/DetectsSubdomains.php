<?php

namespace Codinglabs\Yolo\Concerns;

trait DetectsSubdomains
{
    public function domainHasWwwSubdomain(string $apex, string $domain): bool
    {
        return $apex === $domain || str_starts_with($domain, 'www.');
    }
}
