<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;

/**
 * The ALB issues the redirect before any container: the cert covers both apex
 * and `*.apex`, so the sibling is TLS-valid ahead of it. Only meaningful when the
 * canonical host has an apex/`www` sibling — the driving step gates on that.
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

    #[\Override]
    protected function band(): string
    {
        return 'redirect';
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
        return Manifest::domain() ?? Manifest::apex();
    }
}
