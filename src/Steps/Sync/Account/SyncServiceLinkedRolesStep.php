<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Account;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Iam;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;

/**
 * AWS documents SLRs as implicitly created on first use, but on an account with
 * no prior usage of the service the implicit path can fail with "Unable to
 * assume the service linked role" — so sync creates them explicitly first.
 *
 * SLRs are account-wide singletons owned by AWS: they can't be tagged at
 * creation, legitimately pre-exist, and are shared by every consumer — never
 * reconciled beyond existence, never torn down.
 */
class SyncServiceLinkedRolesStep implements Step
{
    use RecordsChanges;

    /**
     * Application Auto Scaling mints one SLR per service namespace — the generic
     * application-autoscaling.amazonaws.com is not a valid SLR service.
     */
    public const SERVICES = [
        'ecs.amazonaws.com',
        'ecs.application-autoscaling.amazonaws.com',
        'elasticache.amazonaws.com',
    ];

    public function __invoke(array $options): StepResult
    {
        $missing = array_values(array_filter(
            self::SERVICES,
            fn (string $service): bool => ! Iam::serviceLinkedRoleExists($service)
        ));

        if ($missing === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $service) {
            $this->recordChange(Change::make($service, null, 'service-linked role'));
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        foreach ($missing as $service) {
            $this->createServiceLinkedRole($service);
        }

        return StepResult::CREATED;
    }

    protected function createServiceLinkedRole(string $service): void
    {
        try {
            Aws::iam()->createServiceLinkedRole([
                'AWSServiceName' => $service,
            ]);
        } catch (IamException $e) {
            // Another principal (or an implicit create) winning the race reports
            // InvalidInput "has been taken" — the end state is reached either way.
            if ($e->getAwsErrorCode() === 'InvalidInput' && str_contains($e->getMessage(), 'has been taken')) {
                return;
            }

            throw $e;
        }
    }
}
