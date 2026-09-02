<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\EnvManifest;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Read-only access to exactly the surface YOLO inspects (the sync/audit plan pass,
 * `status` log tailing, `status:budget` Cost Explorer). Deliberately NOT AWS's
 * ReadOnlyAccess, which grants ~300 services and `s3:GetObject` on every bucket.
 *
 * One `yolo-{env}-observer` per environment, attached to every app's deployer role
 * so the deploy-time `sync --check` gate can read the whole stack. Per-service read
 * wildcards (`ecs:Describe*`, …) so a new read within a service YOLO already touches
 * can't AccessDenied-abort a deploy — only a brand-new service needs a line here.
 * The document is manifest-derived (no live AWS calls).
 */
class ObserverPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesPolicyDocument;

    public function name(): string
    {
        return $this->keyedName(Iam::OBSERVER_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            IamClient::policy($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::policy($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createPolicy([
            'PolicyName' => $this->name(),
            'Description' => $this->description(),
            'PolicyDocument' => json_encode($this->document()),
            ...Aws::tags($this->tags()),
        ]);
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed read-only inspection of the services YOLO provisions - the drift-check surface for sync and the pre-deploy gate';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * IAM refuses to delete a policy that is still attached anywhere or carries
     * non-default versions, so detach and prune before deletePolicy.
     */
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

    public function document(): array
    {
        $accountId = Aws::accountId();
        $envConfigBucket = Paths::s3EnvConfigBucket();

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    // Describe/List/Get are mostly unscopeable collection ops, so "*" —
                    // but only for YOLO's services.
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => [
                        // compute / networking
                        'ec2:Describe*',
                        'ecs:Describe*',
                        'ecs:List*',
                        'ecr:Describe*',
                        'ecr:List*',
                        'elasticloadbalancing:Describe*',
                        'application-autoscaling:Describe*',
                        // data / cache / queues
                        'rds:Describe*',
                        'rds:ListTagsForResource',
                        'elasticache:Describe*',
                        'elasticache:ListTagsForResource',
                        'sqs:Get*',
                        'sqs:List*',
                        'sns:Get*',
                        'sns:List*',
                        // edge / dns / certs
                        'cloudfront:Get*',
                        'cloudfront:List*',
                        'route53:Get*',
                        'route53:List*',
                        'acm:Describe*',
                        'acm:List*',
                        // observability — Logs reads live in logsStatements() so the
                        // per-app variant can fence log content to one app.
                        'cloudwatch:Describe*',
                        'cloudwatch:Get*',
                        'cloudwatch:List*',
                        'events:Describe*',
                        'events:List*',
                        // cost — status:budget; Cost Explorer has no resource-level permissions.
                        'ce:Describe*',
                        'ce:Get*',
                        'ce:List*',
                        // waf / service discovery / tagging / identity
                        'wafv2:Get*',
                        'wafv2:List*',
                        'servicediscovery:Get*',
                        'servicediscovery:List*',
                        'tag:Get*',
                        'sts:GetCallerIdentity',
                        // IAM collection ops can't be resource-scoped; document reads are scoped below.
                        'iam:ListRoles',
                        'iam:ListPolicies',
                        'iam:ListOpenIDConnectProviders',
                        // collection op, unscopeable
                        's3:ListAllMyBuckets',
                    ],
                ],
                [
                    // Document + metadata reads on YOLO's own identities only.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                        sprintf('arn:aws:iam::%s:policy/yolo-*', $accountId),
                        sprintf('arn:aws:iam::%s:oidc-provider/*', $accountId),
                    ],
                    'Action' => [
                        'iam:GetRole',
                        'iam:GetPolicy',
                        'iam:GetPolicyVersion',
                        'iam:ListPolicyVersions',
                        'iam:ListAttachedRolePolicies',
                        // destroy:app (admin tier) enumerates inline policies and
                        // attachments to detach before deleting.
                        'iam:ListRolePolicies',
                        'iam:ListEntitiesForPolicy',
                        'iam:ListRoleTags',
                        'iam:ListPolicyTags',
                        'iam:GetOpenIDConnectProvider',
                        'iam:ListOpenIDConnectProviderTags',
                    ],
                ],
                [
                    // The deploy-time `sync --check` gate plans the group steps under
                    // this tier, so it must read groups + their inline policy.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:group/yolo-*', $accountId),
                    'Action' => [
                        'iam:GetGroup',
                        'iam:GetGroupPolicy',
                        'iam:ListGroupPolicies',
                        // destroy:app detaches managed policies before deleting the group.
                        'iam:ListAttachedGroupPolicies',
                    ],
                ],
                [
                    // Bucket-level configuration reads; the bucket ARN excludes object
                    // contents (granted narrowly below).
                    'Effect' => 'Allow',
                    'Resource' => 'arn:aws:s3:::yolo-*',
                    'Action' => [
                        's3:GetBucket*',
                        's3:GetEncryptionConfiguration',
                        's3:GetLifecycleConfiguration',
                        's3:GetReplicationConfiguration',
                        's3:ListBucket',
                    ],
                ],
                [
                    // Object reads on the env manifest and app claim files only — the
                    // env-shared `.env` and every other secret are deliberately absent.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvManifest::filename()),
                        sprintf('arn:aws:s3:::%s/apps/*', $envConfigBucket),
                    ],
                    'Action' => [
                        's3:GetObject',
                    ],
                ],
                ...$this->logsStatements(),
                ...$this->sessionStatements(),
            ],
        ];
    }

    /**
     * Client half of the `db:tunnel` SSM port-forward (task-side ssmmessages live on
     * the task role). StartSession authorises against BOTH the task and the session
     * document, so pinning the document means an observer can tunnel but never open
     * a shell. Own statement(s) so the per-app variant can fence the task target.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionStatements(): array
    {
        $region = Manifest::get('region');

        return [
            [
                'Effect' => 'Allow',
                'Resource' => [
                    sprintf('arn:aws:ecs:%s:%s:task/yolo-%s-*', $region, Aws::accountId(), Helpers::environment()),
                    // The AWS-owned session document — its ARN carries no account id.
                    sprintf('arn:aws:ssm:%s::document/AWS-StartPortForwardingSessionToRemoteHost', $region),
                ],
                'Action' => ['ssm:StartSession'],
            ],
            [
                // Session ARNs embed a caller-derived id with no reliable per-user form
                // for assumed roles, so account-wide; the write is only ending a session.
                'Effect' => 'Allow',
                'Resource' => sprintf('arn:aws:ssm:%s:%s:session/*', $region, Aws::accountId()),
                'Action' => ['ssm:TerminateSession', 'ssm:ResumeSession'],
            ],
        ];
    }

    /**
     * Own statement(s) so {@see AppObserverPolicy} can fence log *content* to one
     * app's group — the only observer read AWS lets you scope to a resource.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logsStatements(): array
    {
        return [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => [
                    'logs:Describe*',
                    'logs:Get*',
                    // FilterLogEvents is NOT a Get* action (status:logs tails through it).
                    'logs:Filter*',
                    'logs:ListTagsForResource',
                ],
            ],
        ];
    }
}
