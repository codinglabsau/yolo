<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Credentials;

use InvalidArgumentException;

/**
 * RFC 6238 time-based codes (SHA-1, 6 digits, 30-second steps) — what IAM's
 * virtual MFA devices speak. Only `agent:rotate` needs it: enabling a device takes
 * two consecutive codes, and the seed is never shown to a human to scan.
 */
class Totp
{
    public const int STEP = 30;

    public static function code(string $base32Seed, int $timestamp): string
    {
        $counter = pack('J', intdiv($timestamp, self::STEP));
        $hash = hash_hmac('sha1', $counter, static::base32Decode($base32Seed), binary: true);
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The `otpauth://` form password managers store; the seed alone is the secret.
     */
    public static function uri(string $base32Seed, string $account, string $issuer = 'AWS'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($issuer),
            rawurlencode($account),
            $base32Seed,
            rawurlencode($issuer),
        );
    }

    /**
     * Accepts either a bare base32 seed or an `otpauth://` URI.
     */
    public static function seedFrom(string $value): string
    {
        if (str_starts_with($value, 'otpauth://')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);

            return strtoupper((string) ($query['secret'] ?? ''));
        }

        return strtoupper(str_replace(' ', '', $value));
    }

    protected static function base32Decode(string $seed): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $seed = rtrim(strtoupper($seed), '=');
        $bits = '';

        foreach (str_split($seed) as $character) {
            $value = strpos($alphabet, $character);

            if ($value === false) {
                throw new InvalidArgumentException('TOTP seed is not valid base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(
            fn (string $byte): string => chr((int) bindec($byte)),
            array_filter(str_split($bits, 8), fn (string $byte): bool => strlen($byte) === 8),
        ));
    }
}
