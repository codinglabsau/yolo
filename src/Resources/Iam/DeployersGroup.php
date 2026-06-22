<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;

/**
 * Grant group for per-app deploy: members may assume this app's
 * {@see DeployerRole} and deploy ONE app (`yolo-{env}-{app}-deployers`). The
 * effective per-app grant — deploy mutations DO scope to the app's resources, so
 * membership of this group genuinely confines a developer to deploying this app.
 * Provisioned only when the app has a deployer role (a GitHub repository).
 */
class DeployersGroup extends AssumeRoleGroup implements Deletable
{
    public function name(): string
    {
        return $this->keyedName(Iam::DEPLOYERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    protected function role(): Resource
    {
        return new DeployerRole();
    }

    /**
     * Teardown when the app drops its deployer: IAM refuses to delete a group
     * that still has members, attached managed policies, or inline policies, so
     * remove every user from the group, detach every managed policy, and delete
     * the inline assume-role policy (AssumeRoleGroup's create() put) before
     * deleteGroup. A concurrent delete that already removed the group is tolerated.
     */
    #[\Override]
    public function delete(): void
    {
        try {
            $members = Aws::iam()->getGroup([
                'GroupName' => $this->name(),
            ])['Users'] ?? [];

            foreach ($members as $user) {
                Aws::iam()->removeUserFromGroup([
                    'GroupName' => $this->name(),
                    'UserName' => $user['UserName'],
                ]);
            }

            $attached = Aws::iam()->listAttachedGroupPolicies([
                'GroupName' => $this->name(),
            ])['AttachedPolicies'] ?? [];

            foreach ($attached as $policy) {
                Aws::iam()->detachGroupPolicy([
                    'GroupName' => $this->name(),
                    'PolicyArn' => $policy['PolicyArn'],
                ]);
            }

            $inline = Aws::iam()->listGroupPolicies([
                'GroupName' => $this->name(),
            ])['PolicyNames'] ?? [];

            foreach ($inline as $policyName) {
                Aws::iam()->deleteGroupPolicy([
                    'GroupName' => $this->name(),
                    'PolicyName' => $policyName,
                ]);
            }

            Aws::iam()->deleteGroup([
                'GroupName' => $this->name(),
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
    }
}
