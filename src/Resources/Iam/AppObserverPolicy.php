<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Per-app variant of {@see ObserverPolicy}: the same read surface, with one
 * deliberate narrowing — CloudWatch Logs *content* (FilterLogEvents /
 * GetLogEvents) is fenced to this app's own log group instead of the
 * account-wide "*". Log content is the *only* observer read AWS lets you scope
 * to a resource (cost, metrics and topology APIs are unscopeable collection
 * ops), so this is the one thing per-app observer can actually enforce: it stops
 * an operator — or an agent — granted read on one app from tailing another
 * app's logs, where PII and customer data live.
 *
 * App-scoped: one `yolo-{env}-{app}-observer` per app (the name follows from the
 * App scope through the shared OBSERVER_POLICY token), so a grant can name a
 * single app. Everything else is inherited unchanged from the env policy — the
 * unscopeable reads stay env-wide either way.
 */
class AppObserverPolicy extends ObserverPolicy
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    /**
     * IAM Description fields enforce a restricted character set (no em dashes /
     * smart quotes) — see {@see ObserverPolicy::description()}.
     */
    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only inspection for one app, with log content fenced to the app log group';
    }

    #[\Override]
    public function logsStatements(): array
    {
        $logGroupArn = sprintf(
            'arn:aws:logs:%s:%s:log-group:%s:*',
            Manifest::get('region'),
            Aws::accountId(),
            (new TaskLogGroup())->name(),
        );

        return [
            [
                // Discovery only — DescribeLogGroups/Streams have no resource-level
                // form, so they stay on "*". Group and stream *names* aren't
                // sensitive; reading their *content* is what the fence protects.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['logs:Describe*'],
            ],
            [
                // Log *content* fenced to this app's log group. GetQueryResults
                // (Insights) is deliberately omitted: status:logs tails via
                // FilterLogEvents, and Insights results are unscopeable — granting
                // them would re-open the fence the per-app policy exists to close.
                'Effect' => 'Allow',
                'Resource' => $logGroupArn,
                'Action' => [
                    'logs:GetLogEvents',
                    'logs:GetLogGroupFields',
                    'logs:GetLogRecord',
                    'logs:FilterLogEvents',
                    'logs:ListTagsForResource',
                ],
            ],
        ];
    }
}
