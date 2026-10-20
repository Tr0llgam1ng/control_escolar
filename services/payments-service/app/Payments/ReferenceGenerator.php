<?php

namespace App\Payments;

use Closure;

/**
 * Payment reference = 12 random digits + 1 Luhn check digit (13 digits).
 *
 * The digits come from random_int() (CSPRNG) and are never derived from the student,
 * period or date, so a reference reveals nothing and cannot be guessed from other data.
 * The check digit lets us reject a mistyped reference before touching the database.
 * Uniqueness is guaranteed by the unique index on charges.reference (see ChargeService).
 */
class ReferenceGenerator
{
    public const PAYLOAD_LENGTH = 12;
    public const LENGTH = 13;

    /** @param  Closure():string|null  $payloadSource  only overridden in tests (forced collisions) */
    public function __construct(private ?Closure $payloadSource = null) {}

    public function generate(): string
    {
        $payload = $this->payloadSource
            ? ($this->payloadSource)()
            : $this->randomDigits(self::PAYLOAD_LENGTH);

        return $payload.self::checkDigit($payload);
    }

    public static function checkDigit(string $payload): int
    {
        $sum = 0;
        $double = true; // the rightmost payload digit is doubled

        for ($i = strlen($payload) - 1; $i >= 0; $i--) {
            $digit = (int) $payload[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValid(?string $reference): bool
    {
        if ($reference === null || ! preg_match('/^\d{'.self::LENGTH.'}$/', $reference)) {
            return false;
        }

        $payload = substr($reference, 0, self::PAYLOAD_LENGTH);

        return self::checkDigit($payload) === (int) $reference[self::PAYLOAD_LENGTH];
    }

    private function randomDigits(int $length): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
