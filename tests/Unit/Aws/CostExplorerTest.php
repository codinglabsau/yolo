<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Codinglabs\Yolo\Helpers;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Aws\CostExplorer;
use Aws\CostExplorer\CostExplorerClient;

function bindMockCostExplorerClient(MockHandler $mock): void
{
    Helpers::app()->instance('costExplorer', new CostExplorerClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

it('reads month-to-date unblended spend for an app by its yolo:app tag', function (): void {
    $mock = new MockHandler();
    $mock->append(new Result(['ResultsByTime' => [
        ['Total' => ['UnblendedCost' => ['Amount' => '42.10', 'Unit' => 'USD']]],
    ]]));

    bindMockCostExplorerClient($mock);

    expect(CostExplorer::monthToDateByApp('my-app'))->toBe(42.10);
});

it('returns null spend when Cost Explorer has no data', function (): void {
    $mock = new MockHandler();
    $mock->append(new Result(['ResultsByTime' => [['Total' => []]]]));

    bindMockCostExplorerClient($mock);

    expect(CostExplorer::monthToDateByApp('my-app'))->toBeNull();
});

it('returns null spend when the Cost Explorer read fails', function (): void {
    $mock = new MockHandler();
    $mock->append(new AwsException('denied', new Command('GetCostAndUsage')));

    bindMockCostExplorerClient($mock);

    expect(CostExplorer::monthToDateByApp('my-app'))->toBeNull();
});
