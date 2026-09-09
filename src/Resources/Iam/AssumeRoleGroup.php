<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * The grant layer: an IAM group whose inline policy allows `sts:AssumeRole` on
 * one tier role. Membership IS the access lever; YOLO never manages it (that's
 * the human `yolo permissions` act). The self-service credential-hygiene slice
 * (MFA enrolment, key/password rotation) is deliberately NOT here — it lives in
 * the tier-independent {@see UsersGroup}, so it never waits on a tier grant.
 *
 * The document is deterministic (no live lookups) so it survives the plan pass
 * with nothing created. IAM groups are not taggable, so ownership is encoded in
 * the name — `yolo audit` can't see them; sync drift is the only stray-catcher.
 */
abstract class AssumeRoleGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesGroupPolicy;

    abstract protected function role(): Resource;

    /**
     * Widened by groups whose tier subsumes narrower ones. Must stay deterministic —
     * built from account/env only, never the current app.
     *
     * @return string|array<int, string>
     */
    protected function assumableRoleArns(): string|array
    {
        return sprintf(
            'arn:aws:iam::%s:role/%s',
            Aws::accountId(),
            $this->role()->name(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => 'sts:AssumeRole',
                    'Resource' => $this->assumableRoleArns(),
                ],
            ],
        ];
    }

    protected function policyName(): string
    {
        return sprintf('%s-assume', $this->name());
    }
}
