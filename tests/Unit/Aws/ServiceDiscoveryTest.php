<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Aws\ServiceDiscovery;
use Aws\ServiceDiscovery\ServiceDiscoveryClient;
use Aws\ServiceDiscovery\Exception\ServiceDiscoveryException;

function bindServiceDiscoveryMock(MockHandler $mock): void
{
    Helpers::app()->instance('serviceDiscovery', new ServiceDiscoveryClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

it('retries deleteService past ResourceInUse until the instances finish deregistering', function (): void {
    $mock = new MockHandler();
    $mock->append(new ServiceDiscoveryException('still has instances', new Command('DeleteService'), ['code' => 'ResourceInUse']));
    $mock->append(new Result());
    bindServiceDiscoveryMock($mock);

    ServiceDiscovery::deleteServiceWhenDrained('srv-1', maxAttempts: 5, sleepSeconds: 0);

    // Both queued responses consumed → it retried the ResourceInUse and then succeeded.
    expect($mock->count())->toBe(0);
});

it('rethrows a non-ResourceInUse deleteService error without retrying', function (): void {
    $mock = new MockHandler();
    $mock->append(new ServiceDiscoveryException('denied', new Command('DeleteService'), ['code' => 'AccessDenied']));
    $mock->append(new Result());
    bindServiceDiscoveryMock($mock);

    expect(fn () => ServiceDiscovery::deleteServiceWhenDrained('srv-1', maxAttempts: 5, sleepSeconds: 0))
        ->toThrow(ServiceDiscoveryException::class);

    // Only the first response consumed — it threw immediately, no retry.
    expect($mock->count())->toBe(1);
});
