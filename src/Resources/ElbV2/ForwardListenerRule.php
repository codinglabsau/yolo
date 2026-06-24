<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;

/**
 * Forwards the app's canonical host (`domain`) to its target group. This is the
 * only host the app is served on — the apex/`www` sibling, when there is one, is
 * 301-redirected to it by a {@see RedirectListenerRule} rather than served.
 */
class ForwardListenerRule extends ListenerRule
{
    public function name(): string
    {
        return $this->keyedName();
    }

    public function hosts(): array
    {
        return [Manifest::get('domain') ?? Manifest::apex()];
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
