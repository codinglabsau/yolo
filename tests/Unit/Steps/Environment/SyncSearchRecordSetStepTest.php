<?php

declare(strict_types=1);

use Codinglabs\Yolo\Steps\Sync\Environment\SyncSearchRecordSetStep;

/**
 * The alias-convergence check is where the trailing-dot bug lived: Route 53
 * returns the alias target as a trailing-dot FQDN while the ELBv2 API returns it
 * bare, so an already-correct record re-UPSERTed on every sync and never planned
 * clean — failing the deploy `sync --check` gate on any Typesense env.
 */
it('treats a record converged on the ALB as in sync regardless of the trailing dot', function (string $live, string $albDnsName): void {
    expect(SyncSearchRecordSetStep::aliasMatches($live, $albDnsName))->toBeTrue();
})->with([
    'route53 dotted vs elb bare' => ['yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com.', 'yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com'],
    'both bare' => ['yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com', 'yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com'],
    'both dotted' => ['yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com.', 'yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com.'],
    'case-insensitive (DNS is case-insensitive)' => ['YOLO-Typesense.ap-southeast-2.elb.amazonaws.com.', 'yolo-typesense.ap-southeast-2.elb.amazonaws.com'],
]);

it('flags a genuinely different alias target as drift', function (): void {
    expect(SyncSearchRecordSetStep::aliasMatches(
        'old-alb-123.ap-southeast-2.elb.amazonaws.com.',
        'yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com',
    ))->toBeFalse();
});

it('treats an absent record (no live alias) as not matching, so it plans the create', function (): void {
    expect(SyncSearchRecordSetStep::aliasMatches(null, 'yolo-typesense-724729035.ap-southeast-2.elb.amazonaws.com'))->toBeFalse();
});
