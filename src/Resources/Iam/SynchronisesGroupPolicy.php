<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The create/sync/delete plumbing shared by every YOLO-managed IAM group whose
 * whole grant is a single inline policy document — {@see document()} is the
 * only thing a consumer supplies. IAM groups have no tagging API, so ownership
 * lives in the name and synchroniseTags() is a deliberate no-op.
 *
 * @phpstan-require-implements \Codinglabs\Yolo\Resources\Resource
 */
trait SynchronisesGroupPolicy
{
    use CanonicalisesPolicyDocuments;

    /**
     * @return array<string, mixed>
     */
    abstract public function document(): array;

    public function exists(): bool
    {
        try {
            IamClient::group($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::group($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createGroup(['GroupName' => $this->name()]);

        Aws::iam()->putGroupPolicy([
            'GroupName' => $this->name(),
            'PolicyName' => $this->policyName(),
            'PolicyDocument' => json_encode($this->document()),
        ]);
    }

    /** IAM groups have no tagging API; the name carries ownership. */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * Compared canonically ({@see CanonicalisesPolicyDocuments}) so IAM's reordering
     * and single-element collapsing never read as drift.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $desired = $this->document();
        $live = IamClient::groupPolicy($this->name(), $this->policyName());

        if ($live !== null && $this->policyDocumentsMatch($live, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::iam()->putGroupPolicy([
                'GroupName' => $this->name(),
                'PolicyName' => $this->policyName(),
                'PolicyDocument' => json_encode($desired),
            ]);
        }

        return [Change::make(
            sprintf('%s policy', $this->policyName()),
            $live === null ? 'missing' : 'drifted',
            'reconciled',
        )];
    }

    /**
     * IAM refuses to delete a group that still has members, attached or inline
     * policies, so strip all three first.
     */
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

    abstract protected function policyName(): string;
}
