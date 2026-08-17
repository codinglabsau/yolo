<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;

/**
 * Forwards the app's canonical host (`domain`) to its target group, plus
 * `*.{domain}` when the app serves its own subdomains, plus every additional
 * landlord host ({@see Manifest::additionalDomains()}) — each served as the
 * literal host declared, with no wildcard or redirect inferred for it. The
 * apex/`www` sibling of the canonical host, when there is one, is
 * 301-redirected here by a {@see RedirectListenerRule} rather than served — and
 * because `*.{apex}` would otherwise also match that sibling, redirect rules
 * take a priority band above every forward rule ({@see ListenerRule::PRIORITY_BANDS}).
 *
 * Still one rule with a stable Name tag (`yolo-{env}-{app}`) whatever the host
 * count — never a rule per additional host — so it can never collide with a
 * sibling rule's Name, e.g. the environment's own `search.{domain}` rule
 * ({@see SearchListenerRule}, Name `yolo-{env}-search`), which sits in a wholly
 * separate (Env, not App) scope regardless of host overlap.
 */
class ForwardListenerRule extends ListenerRule
{
    public function name(): string
    {
        return $this->keyedName();
    }

    public function hosts(): array
    {
        return array_values(array_filter([
            Manifest::domain() ?? Manifest::apex(),
            Manifest::wildcardHost(),
            ...Manifest::additionalDomains(),
        ]));
    }

    protected function action(): array
    {
        return [
            'Type' => 'forward',
            'TargetGroupArn' => (new TargetGroup())->arn(),
        ];
    }

    protected function actionDrift(array $liveAction): ?Change
    {
        $liveTargetGroup = $liveAction['TargetGroupArn']
            ?? $liveAction['ForwardConfig']['TargetGroups'][0]['TargetGroupArn']
            ?? null;

        if (($liveAction['Type'] ?? null) === 'forward' && $liveTargetGroup === (new TargetGroup())->arn()) {
            return null;
        }

        return Change::make('action', $liveAction['Type'] ?? null, 'forward');
    }
}
