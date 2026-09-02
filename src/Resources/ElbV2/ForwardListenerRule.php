<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;

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
