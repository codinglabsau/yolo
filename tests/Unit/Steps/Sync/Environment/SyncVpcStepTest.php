<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\Ec2\Ec2Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcStep;

/**
 * A filter-aware EC2 mock: a `DescribeVpcs` carrying a `tag:Name` filter is the
 * env's own VPC lookup (exists()) and returns empty so it reads as not-yet-
 * created; an unfiltered `DescribeVpcs` is the in-use CIDR scan and returns the
 * supplied blocks. (The shared bindMockEc2Client ignores filters, so it can't
 * tell the two `DescribeVpcs` calls apart.)
 *
 * @param  array<int, string>  $existing
 */
function bindFilterAwareVpcMock(array $existing): void
{
    $mock = new class($existing) extends MockHandler
    {
        /** @param array<int, string> $existing */
        public function __construct(protected array $existing) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $args = $cmd->toArray();
            $byName = collect($args['Filters'] ?? [])
                ->contains(fn (array $filter): bool => ($filter['Name'] ?? null) === 'tag:Name');

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeVpcs' => new Result(['Vpcs' => $byName
                    ? []
                    : collect($this->existing)->map(fn (string $cidr): array => ['CidrBlock' => $cidr])->all()]),
                default => new Result(),
            });
        }
    };

    Helpers::app()->instance('ec2', new Ec2Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('surfaces the auto-selected /16 in the plan for a fresh VPC', function (): void {
    bindFilterAwareVpcMock(['10.1.0.0/16']);

    $step = new SyncVpcStep();
    $result = $step(['dry-run' => true]);

    expect($result)->toBe(StepResult::WOULD_CREATE);

    $cidr = collect($step->changes())->firstWhere('attribute', 'cidr block');
    expect($cidr)->not->toBeNull()
        ->and($cidr->to)->toBe('10.2.0.0/16');
});

it('plans cleanly on a greenfield environment with nothing created yet', function (): void {
    bindFilterAwareVpcMock([]);

    $step = new SyncVpcStep();

    expect(fn (): StepResult => $step(['dry-run' => true]))->not->toThrow(Throwable::class)
        ->and($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
});
