<?php

namespace App\Support;

/**
 * Six-digit codes that change every 30 seconds (RFC 6238), as shown by
 * Google Authenticator, Microsoft Authenticator and similar apps.
 * The phone and the server share a secret; no internet or SMS is needed.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // 32 letters/digits = 160 random bits
    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::BASE32[bindec($chunk)], str_split($bits, 5)));
    }

    // The address the app reads from the QR code
    public static function uri(string $secret, string $account, string $issuer = 'RTR Room'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step the code belongs to (now, 30 s before or after, for a phone clock that is a little off),
     * or null. A step at or before $lastUsed is refused, so a code works only once.
     */
    public static function verify(string $secret, string $code, ?int $lastUsed = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = intdiv($time ?? time(), self::PERIOD);
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsed === null || $step > $lastUsed)) {
                return $step;
            }
        }

        return null;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value !== false) {
                $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn ($chunk) => chr(bindec($chunk)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
