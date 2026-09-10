<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

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
    use ManagesCustomerPolicy;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::OBSERVER_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed read-only inspection of the services YOLO provisions - the drift-check surface for sync and the pre-deploy gate';
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
                    // Bucket-level configuration reads on the infrastructure buckets;
                    // the bucket ARN excludes object contents (granted narrowly below).
                    // The app data buckets are deliberately outside: ListBucket there
                    // would enumerate user uploads, which is a data read and lives on
                    // DataReadPolicy. (Dump keys are timestamps, so backups stay in.)
                    'Effect' => 'Allow',
                    'Resource' => $this->infrastructureBucketArns(),
                    'Action' => [
                        's3:GetBucket*',
                        's3:GetEncryptionConfiguration',
                        's3:GetLifecycleConfiguration',
                        's3:GetReplicationConfiguration',
                        's3:ListBucket',
                    ],
                ],
                [
                    // Object reads on the env manifest, app claim files and the
                    // environment's version-of-record only — the env-shared `.env` and
                    // every other secret are deliberately absent.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvManifest::filename()),
                        sprintf('arn:aws:s3:::%s/apps/*', $envConfigBucket),
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvironmentVersion::MARKER_KEY),
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
     * Every YOLO-named bucket except the app data buckets, by type suffix.
     *
     * @return array<int, string>
     */
    protected function infrastructureBucketArns(): array
    {
        return array_map(
            fn (string $suffix): string => sprintf('arn:aws:s3:::yolo-*-%s', $suffix),
            ['config', 'assets', 'logs', 'backups'],
        );
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
