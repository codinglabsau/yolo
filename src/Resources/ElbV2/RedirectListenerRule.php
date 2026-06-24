<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;

/**
 * 301-redirects the apex/`www` sibling of the canonical host to the canonical
 * host, preserving path and query. The redirect is issued by the ALB before the
 * request reaches a container — the cert already covers both halves (apex +
 * `*.apex` wildcard), so the sibling is TLS-valid ahead of the redirect.
 *
 * Only meaningful when the canonical host has a sibling (it is the apex or
 * `www.{apex}`); the step that drives this rule gates on that.
 */
class RedirectListenerRule extends ListenerRule
{
    use ResolvesCanonicalHost;

    public function name(): string
    {
        return $this->keyedName('redirect');
    }

    public function hosts(): array
    {
        return [$this->wwwSibling(Manifest::apex(), $this->canonicalHost())];
    }

    protected function action(): array
    {
        return [
            'Type' => 'redirect',
            'RedirectConfig' => [
                'Protocol' => 'HTTPS',
                'Port' => '443',
                'Host' => $this->canonicalHost(),
                'Path' => '/#{path}',
                'Query' => '#{query}',
                'StatusCode' => 'HTTP_301',
            ],
        ];
    }

    protected function actionDrift(array $liveAction): ?Change
    {
        if (($liveAction['Type'] ?? null) === 'redirect'
            && ($liveAction['RedirectConfig']['Host'] ?? null) === $this->canonicalHost()) {
            return null;
        }

        return Change::make('action', $liveAction['Type'] ?? null, "redirect → {$this->canonicalHost()}");
    }

    protected function canonicalHost(): string
    {
        return Manifest::get('domain') ?? Manifest::apex();
    }
}
