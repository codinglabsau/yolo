<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed customer-managed IAM policy granting **read-only** access to exactly
 * the AWS surface YOLO inspects: the services a `sync`/`audit` plan pass reads to
 * compute drift, plus the operator-facing `status` reads (the Logs tab tails
 * CloudWatch Logs; `status:budget` reads month-to-date spend from Cost Explorer).
 * Deliberately NOT AWS's managed ReadOnlyAccess: that grants read on the entire AWS
 * surface (~300 services) and `s3:GetObject` on every bucket; this grants read on
 * YOLO's finite service set only, with object reads scoped to non-secret config.
 *
 * Env-scoped and shared: one `yolo-{env}-observer` per environment, attached to
 * every app's deployer role (AttachDeployerRolePoliciesStep) so the deploy-time
 * `sync --check` gate can read the whole stack under the deploy role without a new
 * direct grant — and reusable by an operator/admin role that needs read across the
 * environment. The reads themselves are environment-agnostic (Describe/List are
 * mostly unscopeable account-wide ops); env scope just bounds the shared lifecycle
 * and the object-read carve-out to this environment's config bucket.
 *
 * Per-service read *wildcards* (`ecs:Describe*`, …) rather than enumerated actions,
 * so a new sync read within a service YOLO already touches can't AccessDenied-abort
 * a deploy — only adding a brand-new AWS service to YOLO needs a line here. Same
 * co-location discipline as DeployerPolicy: bump the surface in one place.
 *
 * All resource ARNs are constructed deterministically from the manifest (account
 * id, environment), so the document is pure — no live AWS calls. Document drift is
 * reconciled as a plan-visible Change via SynchronisesPolicyDocument.
 */
class ObserverPolicy implements Resource, SynchronisesConfiguration
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

    /**
     * IAM Description fields enforce a restricted character set
     * (tab/LF/CR + printable ASCII + Latin-1 Supplement) — no em dashes,
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed read-only inspection of the services YOLO provisions - the drift-check surface for sync and the pre-deploy gate';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    public function document(): array
    {
        $accountId = Aws::accountId();
        $envConfigBucket = Paths::s3EnvConfigBucket();

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    // Read-only inspection of every service YOLO provisions. Describe/
                    // List/Get are overwhelmingly collection or metadata ops AWS does
                    // not support resource-level permissions for, so they sit on "*" —
                    // but only for YOLO's services, never the whole AWS surface.
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
                        // observability — CloudWatch metrics + EventBridge. The
                        // CloudWatch Logs reads live in logsStatements() (their own
                        // statement) so the per-app observer variant can fence log
                        // content to one app's group.
                        'cloudwatch:Describe*',
                        'cloudwatch:Get*',
                        'cloudwatch:List*',
                        'events:Describe*',
                        'events:List*',
                        // cost — month-to-date spend by app for the budget read
                        // (status:budget). Cost Explorer has no resource-level
                        // permissions, so its reads sit on "*" like the rest.
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
                        // IAM collection ops can't be resource-scoped (they list the
                        // account); document + metadata reads are scoped below.
                        'iam:ListRoles',
                        'iam:ListPolicies',
                        'iam:ListOpenIDConnectProviders',
                        // S3 bucket discovery (collection op, unscopeable).
                        's3:ListAllMyBuckets',
                    ],
                ],
                [
                    // IAM document + metadata reads, scoped to YOLO-managed roles,
                    // policies and the GitHub OIDC provider — the plan reads its own
                    // identities' trust/attachments/versions, never anyone else's.
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
                        'iam:ListRoleTags',
                        'iam:ListPolicyTags',
                        'iam:GetOpenIDConnectProvider',
                        'iam:ListOpenIDConnectProviderTags',
                    ],
                ],
                [
                    // Bucket-level configuration reads (tagging, versioning, policy,
                    // CORS, lifecycle, public-access-block, …) the plan diffs — scoped
                    // to YOLO-named buckets by the bucket ARN, which excludes object
                    // contents (those need the object ARN, granted narrowly below).
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
                    // S3 object reads, scoped to the env-shared *config* the plan and
                    // claim gate read — the env manifest and the app claim files. The
                    // env-shared `.env` (and every other secret) is deliberately NOT
                    // granted, so the observer can never read secrets.
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
            ],
        ];
    }

    /**
     * CloudWatch Logs reads, isolated in their own statement(s) so the per-app
     * variant can override them. The env observer reads log content across the
     * whole account-wide log surface (Describe/Get/Filter on "*", since
     * DescribeLogGroups has no resource-level form); {@see AppObserverPolicy}
     * overrides this to fence the *content* reads to one app's log group — the
     * only observer read AWS lets you scope to a resource.
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
                    // FilterLogEvents is NOT a Get* action — the Logs tab
                    // (status:logs) tails a group's streams through it.
                    'logs:Filter*',
                    'logs:ListTagsForResource',
                ],
            ],
        ];
    }
}
