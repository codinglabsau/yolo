<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

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
class AppObserverPolicy extends ObserverPolicy implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    /**
     * Teardown when the app drops its per-app observer: IAM refuses to delete a
     * customer-managed policy while it is still attached to any entity or while it
     * carries non-default versions, so detach it from every role/group/user it is
     * attached to (the AppObserverRole, here) and prune every non-default version
     * (the SynchronisesPolicyDocument trait may have rolled several) before
     * deletePolicy. A concurrent delete that already removed the policy is
     * tolerated.
     */
    #[\Override]
    public function delete(): void
    {
        try {
            $policyArn = $this->arn();

            $entities = Aws::iam()->listEntitiesForPolicy([
                'PolicyArn' => $policyArn,
            ]);

            foreach ($entities['PolicyRoles'] ?? [] as $role) {
                Aws::iam()->detachRolePolicy([
                    'RoleName' => $role['RoleName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach ($entities['PolicyGroups'] ?? [] as $group) {
                Aws::iam()->detachGroupPolicy([
                    'GroupName' => $group['GroupName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach ($entities['PolicyUsers'] ?? [] as $user) {
                Aws::iam()->detachUserPolicy([
                    'UserName' => $user['UserName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach (IamClient::policyVersions($policyArn) as $version) {
                if (! ($version['IsDefaultVersion'] ?? false)) {
                    Aws::iam()->deletePolicyVersion([
                        'PolicyArn' => $policyArn,
                        'VersionId' => $version['VersionId'],
                    ]);
                }
            }

            Aws::iam()->deletePolicy([
                'PolicyArn' => $policyArn,
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        } catch (ResourceDoesNotExistException) {
            // arn() resolves the policy by listing; a concurrent delete that
            // removed it between exists() and here leaves nothing to do.
        }
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

    /**
     * Same session grant as the env policy, with the task target fenced to this
     * app's own cluster — a per-app observer tunnels through this app's tasks
     * only. The document pin (port-forward only, never a shell) is inherited.
     */
    #[\Override]
    public function sessionStatements(): array
    {
        $region = Manifest::get('region');

        $statements = parent::sessionStatements();
        $statements[0]['Resource'] = [
            sprintf('arn:aws:ecs:%s:%s:task/%s/*', $region, Aws::accountId(), (new EcsCluster())->name()),
            sprintf('arn:aws:ssm:%s::document/AWS-StartPortForwardingSessionToRemoteHost', $region),
        ];

        return $statements;
    }

    #[\Override]
    public function logsStatements(): array
    {
        $region = Manifest::get('region');
        $accountId = Aws::accountId();
        $logGroupName = (new TaskLogGroup())->name();

        // CloudWatch Logs addresses a group two ways, and they are NOT
        // interchangeable in IAM. Log *content* (the streams inside the group)
        // uses the trailing-':*' form; the group *itself* — the target of the
        // tagging API — uses the bare ARN. A ':*' grant does not match a bare-ARN
        // request, so the two reads need two statements.
        $logContentArn = sprintf('arn:aws:logs:%s:%s:log-group:%s:*', $region, $accountId, $logGroupName);
        $logGroupArn = sprintf('arn:aws:logs:%s:%s:log-group:%s', $region, $accountId, $logGroupName);

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
                'Resource' => $logContentArn,
                'Action' => [
                    'logs:GetLogEvents',
                    'logs:GetLogGroupFields',
                    'logs:GetLogRecord',
                    'logs:FilterLogEvents',
                ],
            ],
            [
                // The log group's TAGS, addressed by its bare ARN (ListTagsForResource
                // strips any ':*' before the call). This is the read the pre-deploy
                // `sync --check` gate makes on the task log group to plan tag drift —
                // the deployer role carries this policy, so without the bare-ARN grant
                // every deploy is refused at the in-sync gate. Still fenced to this
                // app's group, never "*".
                'Effect' => 'Allow',
                'Resource' => $logGroupArn,
                'Action' => ['logs:ListTagsForResource'],
            ],
        ];
    }
}
