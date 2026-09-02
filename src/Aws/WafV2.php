<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Every YOLO WAF resource is `REGIONAL` (the ALB scope — `CLOUDFRONT` lives only
 * in us-east-1), so the scope is baked in. WAFv2 has no get-by-name: list the
 * summaries (which carry the Id, ARN and LockToken) and match on Name.
 */
class WafV2
{
    public const SCOPE = 'REGIONAL';

    /**
     * WAFv2 is eventually consistent: a just-created IP set or web ACL isn't
     * immediately referenceable. AWS's documented remedy is to retry.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function retryWhileUnavailable(callable $operation, int $maxAttempts = 5, int $sleepSeconds = 5): mixed
    {
        return self::retryWhileCode($operation, 'WAFUnavailableEntityException', $maxAttempts, $sleepSeconds);
    }

    /**
     * Association state is eventually consistent — a delete racing the
     * disassociation a few steps earlier reports the ACL still associated.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function retryWhileAssociated(callable $operation, int $maxAttempts = 6, int $sleepSeconds = 5): mixed
    {
        return self::retryWhileCode($operation, 'WAFAssociatedItemException', $maxAttempts, $sleepSeconds);
    }

    /**
     * Retrying AccessDenied normally masks a real permission gap — the carve-out
     * exists because sync widens its own tier's policy earlier in the same apply
     * pass, and IAM propagation to WAF can lag that write by seconds, so the first
     * sync to enable logging races its own grant. A real gap still fails, just
     * after the bounded window.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function retryWhileLoggingPermissionsPropagate(callable $operation, int $maxAttempts = 5, int $sleepSeconds = 5): mixed
    {
        return self::retryWhileCode($operation, ['WAFUnavailableEntityException', 'AccessDeniedException'], $maxAttempts, $sleepSeconds);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  string|array<int, string>  $retryableCodes
     * @return T
     */
    private static function retryWhileCode(callable $operation, string|array $retryableCodes, int $maxAttempts, int $sleepSeconds): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (AwsException $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || ! in_array($exception->getAwsErrorCode(), (array) $retryableCodes, true)) {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public static function webAcl(string $name): array
    {
        return static::findByName('listWebACLs', 'WebACLs', $name)
            ?? throw new ResourceDoesNotExistException("Could not find WAF web ACL $name");
    }

    /**
     * WAFv2 models "no logging" as a nonexistent item, not an empty configuration.
     *
     * @return array<string, mixed>|null
     */
    public static function loggingConfiguration(string $webAclArn): ?array
    {
        try {
            return Aws::wafV2()->getLoggingConfiguration([
                'ResourceArn' => $webAclArn,
            ])['LoggingConfiguration'];
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() === 'WAFNonexistentItemException') {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function ipSet(string $name): array
    {
        return static::findByName('listIPSets', 'IPSets', $name)
            ?? throw new ResourceDoesNotExistException("Could not find WAF IP set $name");
    }

    /**
     * @return array<string, string>|null
     */
    protected static function findByName(string $operation, string $key, string $name): ?array
    {
        $marker = null;

        do {
            $response = Aws::wafV2()->{$operation}(array_filter([
                'Scope' => static::SCOPE,
                'NextMarker' => $marker,
            ]));

            foreach ($response[$key] ?? [] as $summary) {
                if ($summary['Name'] === $name) {
                    return $summary;
                }
            }

            $marker = $response['NextMarker'] ?? null;
        } while ($marker !== null && ($response[$key] ?? []) !== []);

        return null;
    }
}
