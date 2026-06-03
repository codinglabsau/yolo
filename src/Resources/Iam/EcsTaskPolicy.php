<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed customer-managed IAM policy granting the four ssmmessages
 * permissions ECS Exec needs. Attached to the shared ECS task role by the
 * AttachEcsTaskRolePoliciesStep.
 *
 * IAM doesn't have a per-resource "tagResource" API that mirrors the other
 * services — tags are written via `createPolicy` at create time and synced
 * via `tagPolicy`. Policy *document* drift is reconciled as a plan-visible
 * Change via the shared SynchronisesPolicyDocument trait (createPolicyVersion
 * + 5-version pruning).
 */
class EcsTaskPolicy implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesPolicyDocument;

    public function name(): string
    {
        return $this->keyedName(Iam::ECS_TASK_POLICY);
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
     * smart quotes, or U+007F – U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed baseline policy granting ECS Exec session channels, SQS queue access, and SES send to the shared task role';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
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
                    // SQS queues via the task role (no static AWS keys in the app).
                    // Shared policy → scoped to every YOLO queue in this environment
                    // (solo `yolo-{env}-{app}` + per-tenant variants).
                    'Effect' => 'Allow',
                    'Resource' => $this->queueArnPattern(),
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
                    // Lets the container send mail via SES through the task role
                    // (no static AWS keys). Send-only, scoped to this region's
                    // verified identities — covers the v1 (SendRawEmail) and v2
                    // (SendEmail) Laravel SES transports.
                    'Effect' => 'Allow',
                    'Resource' => $this->sesIdentityArnPattern(),
                    'Action' => [
                        'ses:SendRawEmail',
                        'ses:SendEmail',
                    ],
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

    protected function queueArnPattern(): string
    {
        return sprintf(
            'arn:aws:sqs:%s:%s:%s-*',
            Manifest::get('region'),
            Aws::accountId(),
            Helpers::keyedResourceName(exclusive: false),
        );
    }
}
