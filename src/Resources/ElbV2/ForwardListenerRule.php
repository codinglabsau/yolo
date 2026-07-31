<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;

/**
 * Forwards the app's canonical host (`domain`) to its target group, plus
 * `*.{domain}` when the app serves its own subdomains. The apex/`www` sibling,
 * when there is one, is 301-redirected here by a {@see RedirectListenerRule}
 * rather than served — and because `*.{apex}` would otherwise also match that
 * sibling, redirect rules take a priority band above every forward rule
 * ({@see ListenerRule::PRIORITY_BANDS}).
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
            Manifest::get('domain') ?? Manifest::apex(),
            Manifest::wildcardHost(),
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
