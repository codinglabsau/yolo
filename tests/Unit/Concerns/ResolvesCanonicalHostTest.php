<?php

declare(strict_types=1);

use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;

function canonicalHostResolver(): object
{
    return new class()
    {
        use ResolvesCanonicalHost;

        public function siblingExists(string $apex, string $domain): bool
        {
            return $this->hasWwwSibling($apex, $domain);
        }

        public function sibling(string $apex, string $domain): string
        {
            return $this->wwwSibling($apex, $domain);
        }
    };
}

describe('hasWwwSibling', function (): void {
    it('is true when the domain is the apex', function (): void {
        expect(canonicalHostResolver()->siblingExists('example.com', 'example.com'))->toBeTrue();
    });

    it('is true when the domain is www.apex', function (): void {
        expect(canonicalHostResolver()->siblingExists('example.com', 'www.example.com'))->toBeTrue();
    });

    it('is false for any other subdomain', function (): void {
        expect(canonicalHostResolver()->siblingExists('example.com', 'app.example.com'))->toBeFalse();
    });
});

describe('wwwSibling', function (): void {
    it('pairs the apex with its www host', function (): void {
        expect(canonicalHostResolver()->sibling('example.com', 'example.com'))->toBe('www.example.com');
    });

    it('pairs the www host with the apex', function (): void {
        expect(canonicalHostResolver()->sibling('tenant.com', 'www.tenant.com'))->toBe('tenant.com');
    });
});
