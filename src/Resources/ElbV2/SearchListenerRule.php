<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Services\Typesense;

/**
 * Env-scoped: the search service belongs to the environment, not any app.
 */
class SearchListenerRule extends ListenerRule
{
    public function name(): string
    {
        return $this->keyedName('search');
    }

    #[\Override]
    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function hosts(): array
    {
        return [(string) Typesense::searchHost()];
    }

    protected function action(): array
    {
        return [
            'Type' => 'forward',
            'TargetGroupArn' => (new SearchTargetGroup())->arn(),
        ];
    }

    protected function actionDrift(array $liveAction): ?Change
    {
        $liveTargetGroup = $liveAction['TargetGroupArn']
            ?? $liveAction['ForwardConfig']['TargetGroups'][0]['TargetGroupArn']
            ?? null;

        if (($liveAction['Type'] ?? null) === 'forward' && $liveTargetGroup === (new SearchTargetGroup())->arn()) {
            return null;
        }

        return Change::make('action', $liveAction['Type'] ?? null, 'forward');
    }
}
