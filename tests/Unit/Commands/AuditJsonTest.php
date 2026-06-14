<?php

declare(strict_types=1);

use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\Commands\AuditCommand;

// The `--json` audit output is built by a pure row-flattener on
// AbstractAuditCommand (reached here through AuditCommand). It takes the
// already-classified + scope-filtered rows, so it can be pinned with plain
// arrays — no Resource Groups Tagging API or ECS mocking.

it('flattens audit resource rows into a clean, encodable machine shape', function (): void {
    $rows = [
        [
            'scope' => Audit::SCOPE_APP,
            'status' => Audit::STATUS_OK,
            'type' => 'ecs',
            'name' => 'yolo-prod-app-web',
            'app' => 'app',
            'reason' => null,
            'arn' => 'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-prod/yolo-prod-app-web',
        ],
        // A shared resource with no `yolo:app` owner (no 'app' key) and a reason.
        [
            'scope' => Audit::SCOPE_ENV,
            'status' => Audit::STATUS_UNEXPECTED,
            'type' => 'dynamodb',
            'name' => 'sessions',
            'reason' => Audit::REASON_UNMANAGED_SERVICE,
            'arn' => 'arn:aws:dynamodb:ap-southeast-2:111111111111:table/sessions',
        ],
    ];

    $json = AuditCommand::auditJsonRows($rows);

    expect($json)->toBe([
        [
            'scope' => 'app',
            'status' => 'ok',
            'type' => 'ecs',
            'name' => 'yolo-prod-app-web',
            'app' => 'app',
            'reason' => null,
            'arn' => 'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-prod/yolo-prod-app-web',
        ],
        [
            'scope' => 'env',
            'status' => 'unexpected',
            'type' => 'dynamodb',
            'name' => 'sessions',
            'app' => null,
            'reason' => 'service no longer provisioned',
            'arn' => 'arn:aws:dynamodb:ap-southeast-2:111111111111:table/sessions',
        ],
    ]);

    expect(json_encode($json))->toBeJson();
});

it('returns an empty list when there are no resources', function (): void {
    expect(AuditCommand::auditJsonRows([]))->toBe([]);
});
