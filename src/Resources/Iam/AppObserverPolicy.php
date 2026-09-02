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
 * Per-app {@see ObserverPolicy}: log *content* is fenced to this app's own group
 * instead of "*". It's the only observer read AWS lets you scope to a resource, so
 * it's the one thing per-app observer can enforce — an operator or agent granted one
 * app can't tail another's logs, where PII lives. Everything else is inherited.
 */
class AppObserverPolicy extends ObserverPolicy implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    /**
     * IAM refuses to delete a policy that is still attached anywhere or carries
     * non-default versions, so detach and prune before deletePolicy.
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
            // Removed between exists() and here — nothing left to do.
        }
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only inspection for one app, with log content fenced to the app log group';
    }

    /** Task target fenced to this app's cluster; the port-forward document pin is inherited. */
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

        // Log *content* uses the ':*' ARN; the group itself (tagging API) uses the
        // bare ARN. A ':*' grant does not match a bare-ARN request.
        $logContentArn = sprintf('arn:aws:logs:%s:%s:log-group:%s:*', $region, $accountId, $logGroupName);
        $logGroupArn = sprintf('arn:aws:logs:%s:%s:log-group:%s', $region, $accountId, $logGroupName);

        return [
            [
                // DescribeLogGroups/Streams have no resource-level form; names aren't sensitive.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['logs:Describe*'],
            ],
            [
                // GetQueryResults (Insights) omitted: results are unscopeable and would
                // re-open the fence this policy exists to close.
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
                // Tag read on the bare ARN — the pre-deploy `sync --check` gate plans
                // tag drift on the task log group under this policy; without it every
                // deploy is refused.
                'Effect' => 'Allow',
                'Resource' => $logGroupArn,
                'Action' => ['logs:ListTagsForResource'],
            ],
        ];
    }
}
