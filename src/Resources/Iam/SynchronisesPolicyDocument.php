<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam as IamClient;

/**
 * Shared document-drift reconciliation for the YOLO-managed customer-managed IAM
 * policies (DeployerPolicy, EcsTaskPolicy, ObserverPolicy, AdminPolicy). IAM has no
 * in-place document update — a changed policy document is pushed by creating a new
 * version and setting it as default.
 *
 * This is wired through SynchronisesConfiguration — NOT a bespoke side-effect the
 * sync step calls under `! dry-run` — on purpose. SyncSteppedCommand computes a
 * plan pass (compute-only, apply=false) and then applies only the steps the plan
 * flagged as having work. A document change computed only at apply time is
 * invisible to that plan, so the step reports clean, gets dropped before apply,
 * and the new version never lands — which silently froze both policies at their
 * create-time version. Returning the drift as a plan-time Change keeps the step
 * on the apply side of the "only-pending-steps" filter.
 *
 * Requires the using Resource to provide name() and document().
 */
trait SynchronisesPolicyDocument
{
    use CanonicalisesPolicyDocuments;

    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $policy = IamClient::policy($this->name());
        $desired = $this->document();

        $currentVersion = IamClient::policyVersion($policy['Arn'], $policy['DefaultVersionId']);
        $live = json_decode(urldecode((string) $currentVersion['Document']), associative: true);

        // Canonical compare, not a naive json_encode string match: IAM round-trips
        // the document with reordered keys/statements and collapses single-element
        // Action/Condition lists to scalars, which a string compare reads as drift —
        // re-versioning on every sync and burning the 5-version cap. Shared
        // with SynchronisesAssumeRolePolicy via CanonicalisesPolicyDocuments.
        if ($this->policyDocumentsMatch($live, $desired)) {
            return [];
        }

        if ($apply) {
            $this->pruneOldestVersionToMakeRoom($policy['Arn']);

            Aws::iam()->createPolicyVersion([
                'PolicyArn' => $policy['Arn'],
                'PolicyDocument' => json_encode($desired),
                'SetAsDefault' => true,
            ]);
        }

        return [Change::make('policy-document', $policy['DefaultVersionId'], 'new version')];
    }

    /**
     * A managed policy holds at most 5 versions and createPolicyVersion does not
     * overwrite — once five exist every push hard-fails with LimitExceeded, which
     * is exactly how a long-lived deployer policy stops accepting updates. Delete
     * the oldest non-default version(s) so the create has room to land (and stay
     * default). The default version is never a prune candidate.
     */
    protected function pruneOldestVersionToMakeRoom(string $policyArn): void
    {
        $versions = collect(IamClient::policyVersions($policyArn));

        // createPolicyVersion needs the policy at <= 4 versions to bring it to 5.
        if ($versions->count() < 5) {
            return;
        }

        $versions
            ->reject(fn (array $version): mixed => $version['IsDefaultVersion'])
            ->sortBy(fn (array $version): string => (string) $version['CreateDate'])
            ->take($versions->count() - 4)
            ->each(fn (array $version) => Aws::iam()->deletePolicyVersion([
                'PolicyArn' => $policyArn,
                'VersionId' => $version['VersionId'],
            ]));
    }
}
