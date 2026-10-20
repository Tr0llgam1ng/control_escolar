<?php

namespace App\Payments;

use InvalidArgumentException;

/**
 * Amounts are compared as integer cents, never as floats
 * (0.1 + 0.2 !== 0.3 must never decide whether a charge is paid).
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        $value = is_string($amount) ? trim($amount) : (is_int($amount) ? (string) $amount : number_format($amount, 2, '.', ''));

        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid amount: {$value}");
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function isValid(mixed $amount): bool
    {
        return (is_string($amount) || is_int($amount) || is_float($amount))
            && preg_match('/^\d{1,8}(\.\d{1,2})?$/', is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount)) === 1;
    }
}
