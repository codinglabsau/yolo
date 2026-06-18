<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Deletable;

/**
 * Per-app variant of {@see ObserverRole}: the role an operator or agent assumes
 * to read ONE app. It carries {@see AppObserverPolicy} (attached by
 * AttachAppObserverRolePolicyStep), so the assumed session reads the app's
 * surface with log content fenced to the app's own group.
 *
 * App-scoped — one `yolo-{env}-{app}-observer-role` per app (the name follows
 * from the App scope through the shared OBSERVER_ROLE token) — so a grant
 * (membership of the app's observers group) can name a single app. Trust is the
 * same same-account-root model as the env observer role; the identity-side
 * `sts:AssumeRole` grant is the gate. Unlike the deployer role it has no OIDC
 * trust — reads are for humans/agents, never CI.
 */
class AppObserverRole extends ObserverRole implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only role for operator/agent inspection of this app';
    }

    /**
     * Teardown when the app drops its per-app observer: IAM refuses to delete a
     * role that still holds policy attachments, so the {@see AppObserverPolicy}
     * attachment (AttachAppObserverRolePolicyStep's work) detaches and any inline
     * policies delete before deleteRole. A concurrent delete that already removed
     * the role is tolerated.
     */
    public function delete(): void
    {
        try {
            $attached = Aws::iam()->listAttachedRolePolicies([
                'RoleName' => $this->name(),
            ])['AttachedPolicies'] ?? [];

            foreach ($attached as $policy) {
                Aws::iam()->detachRolePolicy([
                    'RoleName' => $this->name(),
                    'PolicyArn' => $policy['PolicyArn'],
                ]);
            }

            $inline = Aws::iam()->listRolePolicies([
                'RoleName' => $this->name(),
            ])['PolicyNames'] ?? [];

            foreach ($inline as $policyName) {
                Aws::iam()->deleteRolePolicy([
                    'RoleName' => $this->name(),
                    'PolicyName' => $policyName,
                ]);
            }

            Aws::iam()->deleteRole([
                'RoleName' => $this->name(),
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
    }
}
