<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
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
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * The app task role's baseline runtime grants. App-scoped so nothing here reaches
 * another app's role.
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

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed baseline policy granting ECS Exec session channels, SQS queue access, and SES send to this app\'s task role';
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
                // This app's own queues only (solo + landlord/per-tenant variants).
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
                // Send-only; covers both the v1 (SendRawEmail) and v2 (SendEmail) SES transports.
                'Effect' => 'Allow',
                'Resource' => $this->sesIdentityArnPattern(),
                'Action' => [
                    'ses:SendRawEmail',
                    'ses:SendEmail',
                ],
            ],
            [
                // Env WAF request logs so the app can attribute blocks to rules for
                // ban decisions. Content (':*') form — the read actions address
                // streams. Insights omitted: results are unscopeable.
                'Effect' => 'Allow',
                'Resource' => (new WafLogGroup())->arn() . ':*',
                'Action' => [
                    'logs:GetLogEvents',
                    'logs:GetLogGroupFields',
                    'logs:GetLogRecord',
                    'logs:FilterLogEvents',
                ],
            ],
        ];

        // PutMetricData is unscopeable, so a namespace condition narrows it to YOLO's
        // own metrics. Gated on the same signal that ships the reporter so the grant
        // and its user can't drift.
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

        if (Manifest::has('bucket')) {
            $statements = [...$statements, ...$this->bucketStatements()];
        }

        // Write-only: the producer verifies the archive locally, so a compromised
        // task can't exfiltrate its own dump history (or a sibling's). Abort keeps a
        // failed multipart from stranding parts.
        if (Manifest::backsUpDatabases()) {
            $statements[] = [
                'Effect' => 'Allow',
                'Resource' => sprintf('arn:aws:s3:::%s/%s/*', Paths::s3BackupsBucket(), Manifest::name()),
                'Action' => [
                    's3:PutObject',
                    's3:AbortMultipartUpload',
                ],
            ];
        }

        // The app-side half of a service contract lives on its definition, not here.
        foreach (Manifest::services() as $service) {
            $statements = [...$statements, ...Service::from($service)->definition()->taskRoleStatements()];
        }

        return [
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ];
    }

    /**
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
     * Two ARNs (not one `…-{app}*` glob) so the grant can't reach a sibling app
     * whose name shares this app's prefix.
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
