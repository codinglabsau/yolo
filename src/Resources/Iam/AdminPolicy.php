<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\EnvManifest;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The Admin tier's write half, attached to {@see AdminRole} beside {@see ObserverPolicy}:
 * an operator can run sync/scale but never escalate to general AWS admin.
 *
 * Threat model — read before widening:
 *  - Blast radius is bounded to YOLO's service set. Most write APIs (CreateVpc,
 *    RegisterTaskDefinition, …) have no resource-level scoping, so within a granted
 *    service the write is account-wide — the tier narrows *which services*, not
 *    *which resources*.
 *  - IAM is the escalation surface: every role/policy/OIDC action is scoped to
 *    `yolo-*`, and AttachRolePolicy is conditioned to `yolo-*` customer-managed
 *    policies so AdministratorAccess can never be attached.
 *  - Residual: the tier can rewrite its own `yolo-*` policy documents, and its
 *    `s3:PutBucketPolicy` grant could re-open a per-app config bucket to read the
 *    developer `.env` it is otherwise denied. Closing either needs a permissions
 *    boundary on every YOLO-created role — deliberately not built.
 *
 * Write wildcards per service mirror ObserverPolicy's read wildcards, so a new sync
 * write within an existing service can't AccessDenied-abort a sync. The document is
 * manifest-derived (no live AWS calls) and reconciled via SynchronisesPolicyDocument.
 */
class AdminPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesPolicyDocument;

    public function name(): string
    {
        return $this->keyedName(Iam::ADMIN_POLICY);
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
        return 'YOLO managed write surface for yolo sync and scale, scoped to the services YOLO provisions with IAM fenced to yolo-* against escalation';
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

    /**
     * The buckets teardown may remove, addressed by type suffix. The app data bucket
     * is deliberately absent: it holds user data and is `yolo-*`-named, so a
     * namespace-wide delete grant would silently take it in.
     *
     * @return array<int, string>
     */
    protected static function regeneratableBucketArns(bool $objects = false): array
    {
        return array_map(
            fn (string $suffix): string => sprintf('arn:aws:s3:::yolo-*-%s%s', $suffix, $objects ? '/*' : ''),
            ['config', 'assets', 'logs'],
        );
    }

    public function document(): array
    {
        $accountId = Aws::accountId();
        $envConfigBucket = Paths::s3EnvConfigBucket();

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    // Mostly unscopeable create/modify/delete/tag APIs, so "*" — but
                    // only for the services YOLO provisions.
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => [
                        // compute / networking
                        'ec2:Create*', 'ec2:Delete*', 'ec2:Modify*', 'ec2:Accept*',
                        'ec2:Associate*', 'ec2:Disassociate*',
                        'ec2:Attach*', 'ec2:Detach*',
                        'ec2:Authorize*', 'ec2:Revoke*',
                        'ecs:Create*', 'ecs:Update*', 'ecs:Delete*',
                        'ecs:Register*', 'ecs:Deregister*',
                        'ecs:Put*', 'ecs:Tag*', 'ecs:Untag*',
                        // ECS Exec (`yolo run`, `db:cutover`) fits none of the wildcards above.
                        'ecs:ExecuteCommand',
                        'ecr:Create*', 'ecr:Delete*', 'ecr:Put*',
                        'ecr:Set*', 'ecr:Tag*', 'ecr:Untag*',
                        // Image push (sync builds the env Typesense image). BatchGetImage
                        // is a read but part of the push handshake — docker HEADs the
                        // manifest by digest before PutImage, and Describe*/List* don't
                        // cover it, so a push 403s without it.
                        'ecr:GetAuthorizationToken',
                        'ecr:BatchCheckLayerAvailability',
                        'ecr:BatchGetImage',
                        'ecr:InitiateLayerUpload',
                        'ecr:UploadLayerPart',
                        'ecr:CompleteLayerUpload',
                        'elasticloadbalancing:Create*', 'elasticloadbalancing:Modify*',
                        'elasticloadbalancing:Delete*', 'elasticloadbalancing:Set*',
                        'elasticloadbalancing:Register*', 'elasticloadbalancing:Deregister*',
                        'elasticloadbalancing:Add*', 'elasticloadbalancing:Remove*',
                        'application-autoscaling:RegisterScalableTarget',
                        'application-autoscaling:DeregisterScalableTarget',
                        'application-autoscaling:PutScalingPolicy',
                        'application-autoscaling:DeleteScalingPolicy',
                        'application-autoscaling:TagResource',
                        'application-autoscaling:UntagResource',
                        // data / cache / queues
                        'elasticache:Create*', 'elasticache:Modify*',
                        'elasticache:Delete*', 'elasticache:Add*', 'elasticache:Remove*',
                        // RDS stays wildcard-free so the tier can never touch a database;
                        // the subnet group is YOLO's own network resource. See
                        // NeverDeletesDatabaseTest.
                        'rds:CreateDBSubnetGroup', 'rds:DeleteDBSubnetGroup',
                        'rds:AddTagsToResource', 'rds:RemoveTagsFromResource',
                        'sqs:CreateQueue', 'sqs:DeleteQueue', 'sqs:SetQueueAttributes',
                        'sqs:TagQueue', 'sqs:UntagQueue',
                        'sns:CreateTopic', 'sns:DeleteTopic', 'sns:SetTopicAttributes',
                        'sns:Subscribe', 'sns:Unsubscribe',
                        'sns:TagResource', 'sns:UntagResource',
                        // edge / dns / certs
                        'cloudfront:Create*', 'cloudfront:Update*',
                        'cloudfront:Delete*', 'cloudfront:Tag*', 'cloudfront:Untag*',
                        'route53:CreateHostedZone', 'route53:DeleteHostedZone',
                        'route53:ChangeResourceRecordSets', 'route53:ChangeTagsForResource',
                        'acm:RequestCertificate', 'acm:DeleteCertificate',
                        'acm:AddTagsToCertificate', 'acm:RemoveTagsFromCertificate',
                        // observability
                        'cloudwatch:PutMetricAlarm', 'cloudwatch:DeleteAlarms',
                        'cloudwatch:PutDashboard', 'cloudwatch:DeleteDashboards',
                        'cloudwatch:TagResource', 'cloudwatch:UntagResource',
                        'logs:CreateLogGroup', 'logs:DeleteLogGroup',
                        'logs:PutRetentionPolicy', 'logs:DeleteRetentionPolicy',
                        'logs:TagResource', 'logs:UntagResource',
                        // wafv2:PutLoggingConfiguration provisions the WAF->log-group
                        // delivery on the caller's behalf, so the caller must hold the
                        // (unscopeable) delivery lifecycle + the log-group resource-policy
                        // write. ListLogDeliveries fits none of the observer's read wildcards.
                        'logs:CreateLogDelivery', 'logs:UpdateLogDelivery',
                        'logs:DeleteLogDelivery', 'logs:ListLogDeliveries',
                        'logs:PutResourcePolicy',
                        'events:PutRule', 'events:DeleteRule',
                        'events:PutTargets', 'events:RemoveTargets',
                        'events:TagResource', 'events:UntagResource',
                        // waf / service discovery
                        'wafv2:Create*', 'wafv2:Update*', 'wafv2:Delete*',
                        'wafv2:Put*', 'wafv2:Associate*', 'wafv2:Disassociate*',
                        'wafv2:TagResource', 'wafv2:UntagResource',
                        'servicediscovery:Create*', 'servicediscovery:Delete*',
                        'servicediscovery:Update*', 'servicediscovery:TagResource',
                        'servicediscovery:UntagResource',
                    ],
                ],
                [
                    // Bucket lifecycle + configuration on YOLO-named buckets; no object
                    // contents. Covers the whole yolo-* namespace because the YOLO-owned
                    // app data bucket needs CreateBucket + hardening writes — destructive
                    // verbs deliberately do NOT follow (next statement).
                    'Effect' => 'Allow',
                    'Resource' => 'arn:aws:s3:::yolo-*',
                    'Action' => [
                        's3:CreateBucket',
                        's3:PutBucket*',
                        's3:PutEncryptionConfiguration',
                        's3:PutLifecycleConfiguration',
                        's3:PutReplicationConfiguration',
                        's3:DeleteBucketPolicy',
                    ],
                ],
                [
                    // Delete only the regeneratable buckets, by type suffix: the app data
                    // bucket must not sit inside a delete grant merely for being
                    // YOLO-named, so the IAM boundary and S3::deleteBucket's name guard
                    // agree. ListBucketVersions drives the versioned buckets' sweep.
                    'Effect' => 'Allow',
                    'Resource' => static::regeneratableBucketArns(),
                    'Action' => [
                        's3:ListBucketVersions',
                        's3:DeleteBucket',
                    ],
                ],
                [
                    // Teardown object deletes (asset keys are arbitrary builds/* paths, so
                    // not key-scopeable). Delete-only — never GetObject — so the tier can
                    // empty a bucket without reading the per-app developer `.env`.
                    'Effect' => 'Allow',
                    'Resource' => static::regeneratableBucketArns(objects: true),
                    'Action' => ['s3:DeleteObject', 's3:DeleteObjectVersion'],
                ],
                [
                    // The env manifest, every app's claim file (`apps/*` — env-scoped
                    // admin syncs every app) and the environment's version-of-record.
                    // The per-app developer `.env` lives in the per-app config bucket and
                    // is never granted here.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvManifest::filename()),
                        sprintf('arn:aws:s3:::%s/apps/*', $envConfigBucket),
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvironmentVersion::MARKER_KEY),
                    ],
                    'Action' => ['s3:PutObject'],
                ],
                [
                    // YOLO's own minted env-tier secrets: the env-shared .env (Typesense
                    // admin key) and each app's env-side .env (scoped search key). Get+put
                    // because sync reads what's minted and appends.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, Paths::s3SharedEnvKey()),
                        sprintf('arn:aws:s3:::%s/env/*', $envConfigBucket),
                    ],
                    'Action' => ['s3:GetObject', 's3:PutObject'],
                ],
                [
                    // Lifecycle of YOLO's own roles, policies and OIDC provider — scoped
                    // to yolo-*; no iam:*User, no account-wide IAM.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                        sprintf('arn:aws:iam::%s:policy/yolo-*', $accountId),
                        sprintf('arn:aws:iam::%s:oidc-provider/*', $accountId),
                    ],
                    'Action' => [
                        'iam:CreateRole', 'iam:DeleteRole', 'iam:UpdateRole',
                        'iam:UpdateAssumeRolePolicy',
                        'iam:PutRolePolicy', 'iam:DeleteRolePolicy',
                        'iam:TagRole', 'iam:UntagRole',
                        'iam:CreatePolicy', 'iam:DeletePolicy',
                        'iam:CreatePolicyVersion', 'iam:DeletePolicyVersion',
                        'iam:SetDefaultPolicyVersion',
                        'iam:TagPolicy', 'iam:UntagPolicy',
                        'iam:CreateOpenIDConnectProvider', 'iam:DeleteOpenIDConnectProvider',
                        'iam:UpdateOpenIDConnectProviderThumbprint',
                        'iam:AddClientIDToOpenIDConnectProvider',
                        'iam:TagOpenIDConnectProvider', 'iam:UntagOpenIDConnectProvider',
                    ],
                ],
                [
                    // The escalation chokepoint: only a yolo-* customer-managed policy may
                    // be attached. AWS-managed policies live under account "aws" and never
                    // match, so the tier can't attach AdministratorAccess to itself.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                    'Action' => ['iam:AttachRolePolicy'],
                    'Condition' => [
                        'ArnLike' => [
                            'iam:PolicyARN' => sprintf('arn:aws:iam::%s:policy/yolo-*', $accountId),
                        ],
                    ],
                ],
                [
                    // Detach carries no policy-ARN condition: removing a policy can only
                    // reduce access, and it must stay able to drop an AWS-managed policy an
                    // older YOLO attached. Still fenced to yolo-* roles.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                    'Action' => ['iam:DetachRolePolicy'],
                ],
                [
                    // Grant groups + their inline assume policy; membership via `yolo
                    // permissions`. AddUserToGroup authorises on the group, so an admin
                    // member can grant access to others, including admin — deliberate for
                    // a small senior team.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:group/yolo-*', $accountId),
                    'Action' => [
                        'iam:CreateGroup', 'iam:DeleteGroup', 'iam:GetGroup',
                        'iam:GetGroupPolicy', 'iam:PutGroupPolicy', 'iam:DeleteGroupPolicy',
                        'iam:ListGroupPolicies',
                        'iam:AddUserToGroup', 'iam:RemoveUserFromGroup',
                    ],
                ],
                [
                    // `yolo permissions` picker reads — unscopeable collection ops, the
                    // one read-only IAM exception to the yolo-* fence.
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => [
                        'iam:ListUsers',
                        'iam:ListGroupsForUser',
                    ],
                ],
                [
                    // PassRole for the per-app task roles + shared execution role, fenced
                    // to the ECS tasks service.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                    'Action' => ['iam:PassRole'],
                    'Condition' => [
                        'StringEquals' => [
                            'iam:PassedToService' => 'ecs-tasks.amazonaws.com',
                        ],
                    ],
                ],
                [
                    // Service-linked roles for the specific services only. App Auto Scaling
                    // mints one SLR per namespace, so it's the ECS-suffixed name — the
                    // generic application-autoscaling.amazonaws.com never matches a create.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/aws-service-role/*', $accountId),
                    'Action' => ['iam:CreateServiceLinkedRole'],
                    'Condition' => [
                        'StringEquals' => [
                            'iam:AWSServiceName' => [
                                'ecs.amazonaws.com',
                                'ecs.application-autoscaling.amazonaws.com',
                                'elasticache.amazonaws.com',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
