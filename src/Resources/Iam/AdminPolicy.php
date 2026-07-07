<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
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
 * YOLO-managed customer-managed IAM policy granting the **write** surface `yolo
 * sync` and `yolo scale` exercise — the Admin tier's mutation half. It is attached
 * to {@see AdminRole} alongside {@see ObserverPolicy} (the read half), so the role
 * = "read everything YOLO touches, write everything YOLO provisions". An operator
 * who assumes it can run sync/scale but can never escalate to general AWS admin.
 *
 * THREAT MODEL — read before widening:
 *  - It bounds blast radius to YOLO's **service set**: a capped run can create /
 *    modify / delete within ec2, ecs, ecr, elbv2, autoscaling, elasticache, rds,
 *    sqs, sns, cloudfront, route53, acm, cloudwatch, logs, events, wafv2 and
 *    servicediscovery, but cannot touch services YOLO doesn't use (Lambda,
 *    DynamoDB, IAM *users*, Organizations, billing, …). Many of those write APIs
 *    (CreateVpc, RegisterTaskDefinition, …) have no resource-level scoping, so
 *    within a granted service the write is account-wide — the tier narrows *which
 *    services*, not *which resources*, for unscopeable ops.
 *  - IAM is the escalation surface and is fenced hard: every role/policy/OIDC
 *    action is scoped to `yolo-*`, and AttachRolePolicy is conditioned so only a
 *    `yolo-*` customer-managed policy can be attached — the holder can NOT attach
 *    AWS-managed AdministratorAccess to a role and assume it.
 *  - RESIDUAL (open decision for review): the cap is not airtight against a
 *    determined holder of the tier. Two self-escalation levers remain, both
 *    intrinsic to what sync must legitimately do within `yolo-*`:
 *      (1) sync reconciles its own `yolo-*` policies, so the tier can rewrite a
 *          `yolo-*` policy document and re-attach it; and
 *      (2) sync manages `yolo-*` bucket policies (the asset bucket's CloudFront
 *          OAC policy needs `s3:PutBucketPolicy`), so the same grant lets the tier
 *          rewrite a per-app config bucket's policy to grant itself `s3:GetObject`
 *          on the per-app developer `.env` it otherwise can't read (admin reads
 *          only YOLO's own minted env-tier secrets, not the developer `.env`).
 *    Closing either fully needs a permissions boundary on every YOLO-created role
 *    (so nothing YOLO mints can exceed the boundary) — deliberately NOT built here.
 *    Whether the blast-radius cap above suffices or the boundary is warranted is a
 *    deployment decision; see the PR.
 *
 * Per-service write *wildcards* (`ecs:Create*`, `ecs:Delete*`, …) mirror
 * ObserverPolicy's read-wildcard discipline: a new sync write within a service
 * YOLO already manages can't AccessDenied-abort a sync; only adding a brand-new
 * AWS service needs a line here. The exception is verbs that don't fit a write
 * wildcard — ECR's image-push chain (`GetAuthorizationToken` + the layer-upload
 * APIs) is enumerated explicitly because sync now builds + pushes the env
 * Typesense image. The document is pure (manifest-derived, no live AWS calls)
 * and drift-reconciled via SynchronisesPolicyDocument.
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

    /**
     * IAM Description fields enforce a restricted character set
     * (tab/LF/CR + printable ASCII + Latin-1 Supplement) — no em dashes,
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed write surface for yolo sync and scale, scoped to the services YOLO provisions with IAM fenced to yolo-* against escalation';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown when the environment is torn down: IAM refuses to delete a
     * customer-managed policy while it is still attached to any entity or while
     * it carries non-default versions, so detach it from every role/group/user it
     * is attached to (it rides on the admin role) and prune every non-default
     * version before deletePolicy. A concurrent delete that already removed the
     * policy is tolerated.
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
            // arn() resolves the policy by listing; a concurrent delete that
            // removed it between exists() and here leaves nothing to do.
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
                    // Mutations across YOLO's service set. These create/modify/delete/
                    // tag APIs are overwhelmingly unscopeable (no resource-level
                    // permissions), so they sit on "*" — but only for the services
                    // YOLO actually provisions, never the whole AWS surface.
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
                        'ecr:Create*', 'ecr:Delete*', 'ecr:Put*',
                        'ecr:Set*', 'ecr:Tag*', 'ecr:Untag*',
                        // Image push — sync builds + pushes the env Typesense image
                        // (BuildTypesenseImageStep), so the admin tier needs the same
                        // login + layer-upload chain the deployer uses. These verbs
                        // don't fit the management wildcards above; GetAuthorizationToken
                        // is account-level (unscopeable) and PutImage is already in Put*.
                        // BatchGetImage is a READ but part of the push handshake: docker
                        // HEADs the manifest by digest before PutImage, and that HEAD
                        // maps to BatchGetImage — not covered by the observer's Describe*/
                        // List*, so a push 403s on the manifest check without it.
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
                        // RDS stays wildcard-free so the tier can NEVER touch a
                        // database. The one exception is the DB *subnet group* — YOLO's
                        // own network resource: sync creates it, destroy:environment
                        // reclaims it. Never the instance/cluster/snapshot. (Bootstrap
                        // syncs run --dangerously-skip-permissions, which is why create
                        // never needed this until a capped teardown deletes it.) See
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
                    // S3 bucket lifecycle + configuration, scoped to YOLO-named
                    // buckets. CreateBucket/Put* on the bucket ARN; object contents
                    // are NOT granted here. The only secrets admin can read/write are
                    // YOLO's own env-tier minted keys (the env-shared + env-side
                    // `.env` channels in the env config bucket) — granted as scoped
                    // object actions below, never the per-app developer `.env`.
                    'Effect' => 'Allow',
                    'Resource' => 'arn:aws:s3:::yolo-*',
                    'Action' => [
                        's3:CreateBucket',
                        's3:PutBucket*',
                        's3:PutEncryptionConfiguration',
                        's3:PutLifecycleConfiguration',
                        's3:PutReplicationConfiguration',
                        's3:DeleteBucketPolicy',
                        // Teardown empties then removes the regeneratable yolo-*
                        // buckets — ListBucketVersions for the versioned config
                        // bucket's version sweep (ListBucket comes from the observer
                        // read tier), then DeleteBucket. DeleteBucket can never reach
                        // the app data bucket: it isn't yolo-named, and S3::deleteBucket
                        // hard-blocks it by name regardless.
                        's3:ListBucketVersions',
                        's3:DeleteBucket',
                    ],
                ],
                [
                    // Teardown object deletes — emptying the per-app asset + config
                    // buckets (asset keys are arbitrary builds/* paths, so this can't
                    // be key-scoped) and removing the env-config claim/env files
                    // (destroy:app) and env-shared channels (destroy:environment).
                    // Delete-only on the yolo-* namespace — never GetObject — so the
                    // tier can clear a bucket without ever reading the per-app
                    // developer `.env` (or anything else) it isn't already granted.
                    'Effect' => 'Allow',
                    'Resource' => 'arn:aws:s3:::yolo-*/*',
                    'Action' => ['s3:DeleteObject', 's3:DeleteObjectVersion'],
                ],
                [
                    // The two objects sync writes into the env config bucket: the
                    // env manifest (SeedEnvManifestStep) and each app's claim file
                    // (PublishAppManifestStep writes `apps/{app}.yml` on every
                    // sync:app — env-scoped admin syncs every app, so the whole
                    // `apps/*` prefix). Scoped to exactly these keys. The
                    // env-shared/env-side secret channels are granted in the next
                    // statement; the per-app DEVELOPER `.env` (in the per-app
                    // config bucket) is still never granted here.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, EnvManifest::filename()),
                        sprintf('arn:aws:s3:::%s/apps/*', $envConfigBucket),
                    ],
                    'Action' => ['s3:PutObject'],
                ],
                [
                    // YOLO's env-tier secret channels in the env config bucket: the
                    // env-shared .env (.env.environment.{env}) holding the Typesense
                    // cluster admin key (SyncTypesenseAdminKeyStep), and each app's
                    // environment-side .env (env/.env.{app}) holding its YOLO-minted
                    // scoped search key (SyncTypesenseKeyStep). Get+put: sync reads
                    // what's already minted, appends new keys. These are YOLO's OWN
                    // minted secrets — the per-app developer .env (in the per-app
                    // config bucket) is still never granted here.
                    'Effect' => 'Allow',
                    'Resource' => [
                        sprintf('arn:aws:s3:::%s/%s', $envConfigBucket, Paths::s3SharedEnvKey()),
                        sprintf('arn:aws:s3:::%s/env/*', $envConfigBucket),
                    ],
                    'Action' => ['s3:GetObject', 's3:PutObject'],
                ],
                [
                    // IAM lifecycle for YOLO's own roles, policies and the OIDC
                    // provider — scoped to yolo-* so the tier can reconcile the stack
                    // it owns and nothing else. No iam:*User, no account-wide IAM.
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
                    // Attach is the escalation chokepoint: scoped to yolo-* roles
                    // AND conditioned so ONLY a yolo-* customer-managed policy can
                    // be attached. AWS-managed policies (AdministratorAccess, …)
                    // live under account "aws" and never match — so the tier cannot
                    // grant itself broad access by attaching a managed policy.
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
                    // Detach carries NO policy-ARN condition: removing a policy can
                    // only ever reduce a role's access, never escalate it, so the
                    // chokepoint that fences Attach buys nothing here. It must stay
                    // unconditioned so the tier can clean up an AWS-managed policy an
                    // older YOLO once attached — e.g. dropping AmazonRekognitionReadOnlyAccess
                    // when a service grant moves into the app's own yolo-* policy.
                    // Still fenced to yolo-* roles so it can't touch a foreign role.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/yolo-*', $accountId),
                    'Action' => ['iam:DetachRolePolicy'],
                ],
                [
                    // Grant groups: sync provisions and reconciles the
                    // YOLO grant groups + their inline assume-role policy, and an
                    // admin manages their membership via `yolo permissions`. Scoped
                    // to yolo-* groups — the tier can never touch a non-YOLO group.
                    // AddUserToGroup authorises on the group, so a member of
                    // yolo-{env}-admins can grant access to others, including admin
                    // (a deliberate property for a small senior team).
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
                    // The picker reads for `yolo permissions`: list IAM users and a
                    // user's current groups. Unscopeable collection ops with no
                    // resource-level form, so they sit on "*" — read-only, never a
                    // write, the one IAM exception to the yolo-* fence.
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => [
                        'iam:ListUsers',
                        'iam:ListGroupsForUser',
                    ],
                ],
                [
                    // Hand YOLO's task + execution roles to ECS when sync creates a
                    // service / registers a task definition. Env-scoped admin syncs
                    // every app in the environment, so it's the yolo-* role set (the
                    // per-app task roles + the shared execution role) — fenced by the
                    // PassedToService condition to the ECS tasks service only.
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
                    // Service-linked roles AWS requires the first time ECS / App Auto
                    // Scaling / ElastiCache are used in an account — creatable only
                    // for those specific services, never an arbitrary SLR.
                    'Effect' => 'Allow',
                    'Resource' => sprintf('arn:aws:iam::%s:role/aws-service-role/*', $accountId),
                    'Action' => ['iam:CreateServiceLinkedRole'],
                    'Condition' => [
                        'StringEquals' => [
                            'iam:AWSServiceName' => [
                                'ecs.amazonaws.com',
                                'application-autoscaling.amazonaws.com',
                                'elasticache.amazonaws.com',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
