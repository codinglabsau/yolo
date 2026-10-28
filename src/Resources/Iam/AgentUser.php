<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The one IAM user YOLO owns: a shared, read-only identity coding agents run as,
 * one per environment. Its key and MFA seed live in a shared password-manager item
 * written by `agent:rotate` — sync never mints credentials.
 *
 * Membership is exactly {@see AgentsGroup}, reconciled as configuration: any other
 * group membership is drift sync removes, so the shared key can't be quietly
 * promoted into a human tier.
 */
class AgentUser implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::AGENT_USER);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            IamClient::user($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::user($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createUser([
            'UserName' => $this->name(),
            ...Aws::tags($this->tags()),
        ]);

        Aws::iam()->addUserToGroup([
            'UserName' => $this->name(),
            'GroupName' => (new AgentsGroup())->name(),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamUserTags($this->name(), $this->tags(), $apply);
    }

    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $group = (new AgentsGroup())->name();
        $current = IamClient::groupsForUser($this->name());
        $changes = [];

        if (! in_array($group, $current, true)) {
            if ($apply) {
                Aws::iam()->addUserToGroup(['UserName' => $this->name(), 'GroupName' => $group]);
            }

            $changes[] = Change::make('group ' . $group, null, 'member');
        }

        foreach (array_diff($current, [$group]) as $stray) {
            if ($apply) {
                Aws::iam()->removeUserFromGroup(['UserName' => $this->name(), 'GroupName' => $stray]);
            }

            $changes[] = Change::make('group ' . $stray, 'member', null);
        }

        return $changes;
    }

    /**
     * IAM refuses to delete a user that still holds group memberships, access keys
     * or an MFA device, so strip all three first.
     */
    public function delete(): void
    {
        try {
            foreach (IamClient::groupsForUser($this->name()) as $group) {
                Aws::iam()->removeUserFromGroup(['UserName' => $this->name(), 'GroupName' => $group]);
            }

            foreach (IamClient::accessKeys($this->name()) as $key) {
                Aws::iam()->deleteAccessKey(['UserName' => $this->name(), 'AccessKeyId' => $key['AccessKeyId']]);
            }

            foreach (IamClient::mfaDevices($this->name()) as $device) {
                Aws::iam()->deactivateMFADevice(['UserName' => $this->name(), 'SerialNumber' => $device['SerialNumber']]);
                Aws::iam()->deleteVirtualMFADevice(['SerialNumber' => $device['SerialNumber']]);
            }

            Aws::iam()->deleteUser(['UserName' => $this->name()]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
    }

    /** The virtual MFA device carries the user's name, so it stays inside the admin tier's yolo-* fence. */
    public function mfaDeviceName(): string
    {
        return $this->name();
    }
}
