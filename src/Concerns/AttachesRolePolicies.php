<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Iam;
use Codinglabs\Yolo\Enums\StepResult;

/**
 * Diff a role's managed-policy attachments against the desired set and reconcile
 * additively — attaching only what's missing, recording each as a Change, and
 * writing nothing under --dry-run. Replaces the old blind attachRolePolicy (which
 * fired on every sync and always reported WOULD_SYNC) so an already-attached role
 * reports a clean SYNCED and a dry-run reports exactly which policies it'd attach.
 */
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
            fn (string $arn) => ! $attached->contains($arn),
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
     * Reconcile a role's managed-policy attachments to *exactly* the desired set —
     * attaching what's missing and detaching what's attached but no longer desired,
     * each recorded as a Change and writing nothing under --dry-run. Use this where
     * YOLO owns the role outright (the per-app task role) so the attachment set is
     * declarative: dropping an ARN from the manifest detaches it, no orphan left
     * behind. (attachRolePolicies above is the additive variant for roles that
     * carry a fixed AWS-managed policy that's never removed.)
     *
     * @param  array<int, string>  $desiredArns
     */
    protected function reconcileRolePolicies(string $roleName, array $desiredArns, bool $dryRun): StepResult
    {
        $attached = collect(Iam::attachedRolePolicies($roleName))->pluck('PolicyArn');

        $missing = array_values(array_filter(
            $desiredArns,
            fn (string $arn) => ! $attached->contains($arn),
        ));

        $extra = $attached
            ->reject(fn (string $arn) => in_array($arn, $desiredArns, true))
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
     * The deterministic ARN of a customer-managed policy by name. Constructed
     * rather than looked up so the diff works even on a dry-run where the policy's
     * own create step hasn't run yet.
     */
    protected function customerManagedPolicyArn(string $name): string
    {
        return sprintf('arn:aws:iam::%s:policy/%s', Aws::accountId(), $name);
    }
}
