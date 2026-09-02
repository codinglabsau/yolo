<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam as IamClient;

/**
 * Document drift for YOLO's customer-managed policies. IAM has no in-place update —
 * a new version is created and set default. Wired through SynchronisesConfiguration
 * (not a side-effect under `! dry-run`) because sync applies only the steps the plan
 * flagged; a change computed at apply time is invisible to the plan and never lands.
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

        // Canonical compare: IAM round-trips with reordered keys and single-element
        // lists collapsed, which a string compare reads as drift — re-versioning
        // every sync and burning the 5-version cap.
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
     * A managed policy holds at most 5 versions and createPolicyVersion never
     * overwrites — at five every push fails LimitExceeded. The default version is
     * never pruned.
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
