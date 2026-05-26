<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\AppScoped;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role assumed by AWS Elemental MediaConvert. Direct mirror of
 * EcsTaskRole, but app-exclusive (one per app) so it carries the yolo:app owner
 * tag. Permission policies are attached separately by AttachMediaConvertRolePoliciesStep.
 */
class MediaConvertRole implements AppScoped, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE);
    }

    public function exists(): bool
    {
        try {
            IamClient::role($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::role($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createRole([
            'RoleName' => $this->name(),
            'Description' => $this->description(),
            'AssumeRolePolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * IAM Description character set — see EcsTaskRole::description().
     * Validated by IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed MediaConvert role';
    }

    public function synchroniseTags(): void
    {
        Aws::iam()->tagRole([
            'RoleName' => $this->name(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseAssumeRolePolicy(): void
    {
        Aws::iam()->updateAssumeRolePolicy([
            'RoleName' => $this->name(),
            'PolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
        ]);
    }

    public function assumeRolePolicyDocument(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'mediaconvert.amazonaws.com'],
                    'Action' => 'sts:AssumeRole',
                ],
            ],
        ];
    }
}
