<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Enums\Service;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * YOLO-managed customer-managed IAM policy granting this app's ECS task role its
 * baseline runtime permissions: the four ssmmessages permissions ECS Exec needs,
 * SQS access scoped to this app's own queues, and SES send — plus read+write on
 * the application data bucket when the manifest declares one (`bucket`). App-scoped
 * so the grants are this app's alone and never reach another app's role — attached
 * to the app task role by AttachEcsTaskRolePoliciesStep.
 *
 * IAM doesn't have a per-resource "tagResource" API that mirrors the other
 * services — tags are written via `createPolicy` at create time and synced
 * via `tagPolicy`. Policy *document* drift is reconciled as a plan-visible
 * Change via the shared SynchronisesPolicyDocument trait (createPolicyVersion
 * + 5-version pruning).
 */
class EcsTaskPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesPolicyDocument;

    public function name(): string
    {
        return $this->keyedName(Iam::ECS_TASK_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::App;
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
     * smart quotes, or U+007F – U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed baseline policy granting ECS Exec session channels, SQS queue access, and SES send to this app\'s task role';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown when the app is removed: IAM refuses to delete a customer-managed
     * policy while it is still attached to any entity or while it carries
     * non-default versions, so detach it from every role/group/user it is attached
     * to (the task role, here) and prune every non-default version (the
     * SynchronisesPolicyDocument trait may have rolled several) before
     * deletePolicy. A concurrent delete that already removed the policy is
     * tolerated.
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
        $statements = [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => [
                    'ssmmessages:CreateControlChannel',
                    'ssmmessages:CreateDataChannel',
                    'ssmmessages:OpenControlChannel',
                    'ssmmessages:OpenDataChannel',
                ],
            ],
            [
                // Lets the container dispatch to and work the YOLO-provisioned
                // SQS queues via the task role. Per-app policy → scoped to this
                // app's own queues only (solo `yolo-{env}-{app}` + landlord/
                // per-tenant variants), never another app's.
                'Effect' => 'Allow',
                'Resource' => $this->queueArnPatterns(),
                'Action' => [
                    'sqs:SendMessage',
                    'sqs:SendMessageBatch',
                    'sqs:ReceiveMessage',
                    'sqs:DeleteMessage',
                    'sqs:DeleteMessageBatch',
                    'sqs:ChangeMessageVisibility',
                    'sqs:GetQueueAttributes',
                    'sqs:GetQueueUrl',
                ],
            ],
            [
                // Lets the container send mail via SES through the task role.
                // Send-only, scoped to this region's verified identities —
                // covers the v1 (SendRawEmail) and v2 (SendEmail) Laravel SES
                // transports.
                'Effect' => 'Allow',
                'Resource' => $this->sesIdentityArnPattern(),
                'Action' => [
                    'ses:SendRawEmail',
                    'ses:SendEmail',
                ],
            ],
        ];

        // When web autoscaling burst is on, the saturation emitter publishes the
        // real-time WorkerSaturation metric via PutMetricData. That action has no
        // resource-level scoping, so it's narrowed by a namespace condition to YOLO's
        // own metrics — the task role can publish nothing else. Gated on the same
        // signal that builds the emitter and metrics Caddyfile, so the grant and the
        // process using it can't drift.
        if (Manifest::usesMetricsCaddyfile()) {
            $statements[] = [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['cloudwatch:PutMetricData'],
                'Condition' => [
                    'StringEquals' => ['cloudwatch:namespace' => WebBurstPolicy::METRIC_NAMESPACE],
                ],
            ];
        }

        // When the manifest declares an application data bucket, grant this app's
        // task role read+write to that bucket. Scoped to the one declared bucket —
        // the per-app role means this can't reach another app's bucket.
        if (Manifest::has('bucket')) {
            $statements = [...$statements, ...$this->bucketStatements()];
        }

        // Each consumed service yields the statements its consumption grants —
        // the app-side half of the service contract lives on the service's
        // definition (ServiceDefinition::taskRoleStatements()), so a new
        // service never edits this class.
        foreach (Manifest::services() as $service) {
            $statements = [...$statements, ...Service::from($service)->definition()->taskRoleStatements()];
        }

        return [
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ];
    }

    /**
     * Read+write on the declared application data bucket: object get/put/delete
     * and ACL get/set (plus multipart for large uploads) on `…/*`, and
     * bucket-level listing + location on the bucket itself.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bucketStatements(): array
    {
        $bucketArn = (new S3Bucket())->arn();

        return [
            [
                'Effect' => 'Allow',
                'Resource' => $bucketArn . '/*',
                'Action' => [
                    's3:GetObject',
                    's3:GetObjectAcl',
                    's3:PutObject',
                    's3:PutObjectAcl',
                    's3:DeleteObject',
                    's3:AbortMultipartUpload',
                    's3:ListMultipartUploadParts',
                ],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => $bucketArn,
                'Action' => [
                    's3:ListBucket',
                    's3:ListBucketMultipartUploads',
                    's3:GetBucketLocation',
                ],
            ],
        ];
    }

    protected function sesIdentityArnPattern(): string
    {
        return sprintf(
            'arn:aws:ses:%s:%s:identity/*',
            Manifest::get('region'),
            Aws::accountId(),
        );
    }

    /**
     * The ARNs of this app's SQS queues: the solo queue is named exactly
     * `yolo-{env}-{app}`, and the landlord/per-tenant queues are
     * `yolo-{env}-{app}-*`. Two ARNs (not one `…-{app}*` glob) so the grant
     * can't reach a sibling app whose name shares this app's prefix.
     *
     * @return array<int, string>
     */
    protected function queueArnPatterns(): array
    {
        $base = Helpers::keyedResourceName();

        return [
            sprintf('arn:aws:sqs:%s:%s:%s', Manifest::get('region'), Aws::accountId(), $base),
            sprintf('arn:aws:sqs:%s:%s:%s-*', Manifest::get('region'), Aws::accountId(), $base),
        ];
    }
}
