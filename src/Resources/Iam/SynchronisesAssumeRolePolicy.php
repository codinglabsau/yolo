<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam as IamClient;

/**
 * Trust-policy drift for YOLO roles, wired through SynchronisesConfiguration — NOT a
 * side-effect under `! dry-run` — because sync applies only the steps the plan
 * flagged. A rewrite computed at apply time is invisible to the plan, so the step
 * reports clean, is pruned, and the drifted trust never heals (e.g. a `tag: true`
 * deployer stuck trusting `refs/heads/main`).
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
     * IAM returns the trust document URL-encoded on the role record.
     *
     * @return array<string, mixed>
     */
    protected function liveAssumeRolePolicyDocument(): array
    {
        return json_decode(rawurldecode((string) IamClient::role($this->name())['AssumeRolePolicyDocument']), associative: true);
    }

    /**
     * The `sub` claim (branch vs tag ref) is the security-relevant attribute, so
     * surface it directly when present.
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
     * Named distinctly from DeployerRole::subjectClaim() so the trait isn't shadowed.
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
