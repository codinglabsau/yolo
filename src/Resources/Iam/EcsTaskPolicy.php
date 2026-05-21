<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed customer-managed IAM policy granting the four ssmmessages
 * permissions ECS Exec needs. Attached to the shared ECS task role by the
 * AttachEcsTaskRolePoliciesStep.
 *
 * IAM doesn't have a per-resource "tagResource" API that mirrors the other
 * services — tags are written via `createPolicy` at create time and synced
 * via `tagPolicy`. Policy *document* drift is reconciled via `createPolicyVersion`.
 */
class EcsTaskPolicy implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName(Iam::ECS_TASK_POLICY, exclusive: false);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
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
            'Description' => 'YOLO managed policy granting ECS Exec session channel permissions to the shared task role',
            'PolicyDocument' => json_encode($this->document()),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::iam()->tagPolicy([
            'PolicyArn' => $this->arn(),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * Policy-document drift is reconciled by creating a new version and
     * setting it as default. AWS keeps up to 5 versions per managed policy.
     */
    public function synchroniseDocument(): void
    {
        $policy = IamClient::policy($this->name());
        $document = json_encode($this->document());

        $currentVersion = IamClient::policyVersion($policy['Arn'], $policy['DefaultVersionId']);

        if (urldecode($currentVersion['Document']) === $document) {
            return;
        }

        Aws::iam()->createPolicyVersion([
            'PolicyArn' => $policy['Arn'],
            'PolicyDocument' => $document,
            'SetAsDefault' => true,
        ]);
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
            ],
        ];
    }
}
