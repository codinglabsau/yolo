<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam;
use Codinglabs\Yolo\Enums\StepResult;

trait AttachesRolePolicies
{
    use RecordsChanges;

    /**
     * @param  array<int, string>  $desiredArns
     */
    protected function attachRolePolicies(string $roleName, array $desiredArns, bool $dryRun): StepResult
    {
        $attached = collect(Iam::attachedRolePolicies($roleName))->pluck('PolicyArn');

        $missing = array_values(array_filter(
            $desiredArns,
            fn (string $arn): bool => ! $attached->contains($arn),
        ));

        if ($missing === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $arn) {
            $this->recordChange(Change::make('attached-policy', null, $arn));
        }

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        foreach ($missing as $arn) {
            Aws::iam()->attachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $arn,
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * Exact reconcile (detaches extras too) — only for roles YOLO owns outright;
     * attachRolePolicies() is the additive variant for roles carrying a fixed
     * AWS-managed policy that's never removed.
     *
     * @param  array<int, string>  $desiredArns
     */
    protected function reconcileRolePolicies(string $roleName, array $desiredArns, bool $dryRun): StepResult
    {
        $attached = collect(Iam::attachedRolePolicies($roleName))->pluck('PolicyArn');

        $missing = array_values(array_filter(
            $desiredArns,
            fn (string $arn): bool => ! $attached->contains($arn),
        ));

        $extra = $attached
            ->reject(fn (string $arn): bool => in_array($arn, $desiredArns, true))
            ->values()
            ->all();

        if ($missing === [] && $extra === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $arn) {
            $this->recordChange(Change::make('attached-policy', null, $arn));
        }

        foreach ($extra as $arn) {
            $this->recordChange(Change::make('detached-policy', $arn, null));
        }

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        foreach ($missing as $arn) {
            Aws::iam()->attachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $arn,
            ]);
        }

        foreach ($extra as $arn) {
            Aws::iam()->detachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $arn,
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * Constructed rather than looked up so the diff works on a plan pass where the
     * policy's own create step hasn't run yet.
     */
    protected function customerManagedPolicyArn(string $name): string
    {
        return sprintf('arn:aws:iam::%s:policy/%s', Aws::accountId(), $name);
    }
}
