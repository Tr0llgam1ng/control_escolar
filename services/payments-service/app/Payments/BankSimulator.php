<?php

namespace App\Payments;

use App\Models\TestCard;

/**
 * Local card processor: validates the card against the test_cards table and, when everything
 * checks out, debits its balance. No external service is called.
 * Must run inside a DB transaction (the card row is locked for update).
 */
class BankSimulator
{
    /**
     * @param  array{number:string, holder_name:string, exp_month:int, exp_year:int, cvv:string}  $input
     * @return array{approved:bool, card:?TestCard, reason:?string, authorization_code:?string}
     */
    public function charge(array $input, int $amountCents): array
    {
        $card = TestCard::where('number', $input['number'])->lockForUpdate()->first();

        $decline = fn (string $reason) => ['approved' => false, 'card' => $card, 'reason' => $reason, 'authorization_code' => null];

        if (! $card) {
            return $decline('Tarjeta no reconocida por el banco.');
        }
        if ($card->status !== TestCard::ACTIVE) {
            return $decline('Tarjeta bloqueada. Comunícate con tu banco.');
        }
        if ($card->exp_month !== (int) $input['exp_month'] || $card->exp_year !== (int) $input['exp_year']) {
            return $decline('La fecha de vencimiento no coincide con la tarjeta.');
        }
        if ($card->isExpired()) {
            return $decline('La tarjeta está vencida.');
        }
        if (! password_verify($input['cvv'], $card->cvv_hash)) {
            return $decline('El código de seguridad (CVV) es incorrecto.');
        }
        if (Money::toCents((string) $card->balance) < $amountCents) {
            return $decline('Fondos insuficientes.');
        }

        $card->balance = Money::fromCents(Money::toCents((string) $card->balance) - $amountCents);
        $card->save();

        return [
            'approved' => true,
            'card' => $card,
            'reason' => null,
            'authorization_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        ];
    }

    /** Luhn check over the card number (catches typos before asking the bank). */
    public static function luhnValid(string $number): bool
    {
        if (! preg_match('/^\d{13,19}$/', $number)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $d = (int) $number[$i];
            if ($double && ($d *= 2) > 9) {
                $d -= 9;
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
