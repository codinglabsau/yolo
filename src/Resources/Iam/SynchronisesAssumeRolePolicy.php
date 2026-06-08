<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam as IamClient;

/**
 * Shared trust-policy drift reconciliation for the YOLO-managed IAM roles
 * (DeployerRole, EcsTaskRole, MediaConvertRole, EcsExecutionRole). A role's
 * assume-role (trust) policy can drift after creation — most consequentially the
 * deployer role's `sub` condition when an environment switches from a branch to a
 * tag — and IAM replaces it in place via UpdateAssumeRolePolicy.
 *
 * This is wired through SynchronisesConfiguration — NOT a bespoke side-effect the
 * sync step calls under `! dry-run` — on purpose. SyncSteppedCommand computes a
 * plan pass (compute-only, apply=false) and then applies only the steps the plan
 * flagged as having work. A trust rewrite computed only at apply time is invisible
 * to that plan, so the step reports clean, gets dropped before apply, and the
 * drifted trust never heals — exactly the bug that left a `tag: true` deployer
 * role stuck trusting `refs/heads/main` so its OIDC deploy could never assume it.
 * Returning the drift as a plan-time Change keeps the step on the apply side of
 * the "only-pending-steps" filter (same shape as SynchronisesPolicyDocument).
 *
 * Requires the using Resource to provide name() and assumeRolePolicyDocument().
 */
trait SynchronisesAssumeRolePolicy
{
    use CanonicalisesPolicyDocuments;

    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $live = $this->liveAssumeRolePolicyDocument();
        $desired = $this->assumeRolePolicyDocument();

        if ($this->policyDocumentsMatch($live, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::iam()->updateAssumeRolePolicy([
                'RoleName' => $this->name(),
                'PolicyDocument' => json_encode($desired),
            ]);
        }

        return [$this->trustPolicyChange($live, $desired)];
    }

    /**
     * The role's live trust document. IAM returns it URL-encoded on the role
     * record; decode to the same array shape as assumeRolePolicyDocument().
     *
     * @return array<string, mixed>
     */
    protected function liveAssumeRolePolicyDocument(): array
    {
        return json_decode(rawurldecode((string) IamClient::role($this->name())['AssumeRolePolicyDocument']), associative: true);
    }

    /**
     * Render the drift as a single Change. The `sub` claim is the human-meaningful,
     * security-relevant attribute (branch vs tag ref), so surface it directly when
     * present; otherwise report the document drift generically.
     *
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $desired
     */
    protected function trustPolicyChange(array $live, array $desired): Change
    {
        $liveSubject = $this->subjectClaimFrom($live);
        $desiredSubject = $this->subjectClaimFrom($desired);

        if ($liveSubject !== null || $desiredSubject !== null) {
            return Change::make('trust sub', $liveSubject, $desiredSubject);
        }

        return Change::make('trust-policy', 'drifted', 'reconciled');
    }

    /**
     * The OIDC `sub` condition value (which ref may assume the role) from a given
     * document, or null for a service-principal trust that carries no such
     * condition. Named distinctly from DeployerRole::subjectClaim() (which renders
     * the *desired* sub from the manifest) so the trait isn't shadowed when a role
     * defines its own.
     *
     * @param  array<string, mixed>  $document
     */
    protected function subjectClaimFrom(array $document): ?string
    {
        foreach ($document['Statement'] ?? [] as $statement) {
            foreach ($statement['Condition']['StringLike'] ?? [] as $key => $value) {
                if (str_ends_with((string) $key, ':sub')) {
                    return is_array($value) ? implode(',', $value) : $value;
                }
            }
        }

        return null;
    }
}
