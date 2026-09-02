<?php

namespace Codinglabs\Yolo\Aws;

use RuntimeException;
use Codinglabs\Yolo\Aws;
use Aws\ServiceDiscovery\Exception\ServiceDiscoveryException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class ServiceDiscovery
{
    public static function privateNamespace(string $name): array
    {
        $token = null;

        do {
            $result = Aws::serviceDiscovery()->listNamespaces(array_filter([
                'Filters' => [['Name' => 'TYPE', 'Values' => ['DNS_PRIVATE'], 'Condition' => 'EQ']],
                'NextToken' => $token,
            ]));

            foreach ($result['Namespaces'] ?? [] as $namespace) {
                if ($namespace['Name'] === $name) {
                    return $namespace;
                }
            }

            $token = $result['NextToken'] ?? null;
        } while ($token !== null);

        throw new ResourceDoesNotExistException("Could not find private DNS namespace $name");
    }

    public static function service(string $namespaceId, string $name): array
    {
        $token = null;

        do {
            $result = Aws::serviceDiscovery()->listServices(array_filter([
                'Filters' => [['Name' => 'NAMESPACE_ID', 'Values' => [$namespaceId], 'Condition' => 'EQ']],
                'NextToken' => $token,
            ]));

            foreach ($result['Services'] ?? [] as $service) {
                if ($service['Name'] === $name) {
                    return $service;
                }
            }

            $token = $result['NextToken'] ?? null;
        } while ($token !== null);

        throw new ResourceDoesNotExistException("Could not find service discovery service $name");
    }

    /**
     * Teardown deletes these first — AWS refuses to delete a non-empty namespace.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function services(string $namespaceId): array
    {
        $services = [];
        $token = null;

        do {
            $result = Aws::serviceDiscovery()->listServices(array_filter([
                'Filters' => [['Name' => 'NAMESPACE_ID', 'Values' => [$namespaceId], 'Condition' => 'EQ']],
                'NextToken' => $token,
            ]));

            $services = [...$services, ...($result['Services'] ?? [])];
            $token = $result['NextToken'] ?? null;
        } while ($token !== null);

        return $services;
    }

    /**
     * ECS → Cloud Map instance deregistration after a task stops is eventual, so a
     * delete right after cluster teardown throws ResourceInUse even though the
     * tasks are gone.
     */
    public static function deleteServiceWhenDrained(string $serviceId, int $maxAttempts = 24, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::serviceDiscovery()->deleteService(['Id' => $serviceId]);

                return;
            } catch (ServiceDiscoveryException $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'ResourceInUse') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /**
     * Namespace and service mutations complete out-of-band; resolving a
     * just-created resource before its operation succeeds reads as absent.
     */
    public static function waitForOperation(string $operationId, int $timeout = 300): void
    {
        $deadline = time() + $timeout;

        do {
            $operation = Aws::serviceDiscovery()->getOperation(['OperationId' => $operationId])['Operation'];

            if (($operation['Status'] ?? null) === 'SUCCESS') {
                return;
            }

            if (($operation['Status'] ?? null) === 'FAIL') {
                throw new RuntimeException(sprintf(
                    'Service discovery operation %s failed: %s',
                    $operationId,
                    $operation['ErrorMessage'] ?? 'unknown error',
                ));
            }

            sleep(2);
        } while (time() < $deadline);

        throw new RuntimeException("Timed out waiting for service discovery operation $operationId");
    }
}
