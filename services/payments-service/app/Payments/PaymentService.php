<?php

namespace App\Payments;

use App\Models\Charge;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

/** How a reference gets paid: card (bank simulator), cash (at the window) or transfer (not available yet). */
class PaymentService
{
    public function __construct(private ChargeService $charges, private BankSimulator $bank) {}

    /**
     * Student pays with a card. Every attempt is recorded; an approved one approves the reference.
     *
     * @return array{result:string, message:string, charge:Charge, transaction:PaymentTransaction}
     */
    public function payWithCard(int $chargeId, array $card, array $actor): array
    {
        return DB::transaction(function () use ($chargeId, $card, $actor) {
            $charge = $this->payableCharge($chargeId);
            $outcome = $this->bank->charge($card, $charge->amountCents());

            $transaction = $charge->transactions()->create([
                'method' => Charge::CARD,
                'status' => $outcome['approved'] ? PaymentTransaction::APPROVED : PaymentTransaction::REJECTED,
                'amount' => $charge->amount,
                'test_card_id' => $outcome['card']?->id,
                'card_brand' => $outcome['card']?->brand,
                'card_last4' => substr($card['number'], -4),
                'authorization_code' => $outcome['authorization_code'],
                'card_balance_after' => $outcome['approved'] ? $outcome['card']->balance : null,
                'decline_reason' => $outcome['reason'],
            ]);

            if (! $outcome['approved']) {
                // The student chose card even if the bank declined it; they can still switch to cash themselves.
                if ($charge->payment_method !== Charge::CARD) {
                    $charge->payment_method = Charge::CARD;
                    $charge->save();
                }
                Audit::record($charge, 'card_declined', Charge::PENDING, Charge::PENDING, $actor, $outcome['reason'], ['last4' => $transaction->card_last4]);

                return [
                    'result' => Charge::REJECTED,
                    'message' => 'Pago rechazado: '.$outcome['reason'].' Tu referencia sigue pendiente; puedes intentar de nuevo.',
                    'charge' => $charge,
                    'transaction' => $transaction,
                ];
            }

            $charge->status = Charge::APPROVED;
            $charge->payment_method = Charge::CARD;
            $charge->paid_at = now();
            $charge->save();
            Audit::record($charge, 'card_approved', Charge::PENDING, Charge::APPROVED, $actor, null, [
                'last4' => $transaction->card_last4, 'authorization_code' => $transaction->authorization_code,
            ]);

            return [
                'result' => Charge::APPROVED,
                'message' => "Pago aprobado. Autorización {$transaction->authorization_code}.",
                'charge' => $charge,
                'transaction' => $transaction,
            ];
        });
    }

    /** Student chooses to pay cash at the school window: the reference stays pending until Control Escolar receives it. */
    public function chooseCash(int $chargeId, array $actor): Charge
    {
        return DB::transaction(function () use ($chargeId, $actor) {
            $charge = $this->payableCharge($chargeId);
            if ($charge->payment_method !== Charge::CASH) {
                $charge->payment_method = Charge::CASH;
                $charge->save();
                Audit::record($charge, 'cash_selected', Charge::PENDING, Charge::PENDING, $actor);
            }

            return $charge;
        });
    }

    /**
     * Control Escolar received cash at the window. The amount received must cover the total;
     * the change is recorded. Approves the reference.
     *
     * @return array{charge:Charge, transaction:PaymentTransaction}
     */
    public function receiveCash(int $chargeId, string $amountReceived, array $actor): array
    {
        return DB::transaction(function () use ($chargeId, $amountReceived, $actor) {
            $charge = $this->charges->lock($chargeId);
            abort_if($charge->status === Charge::APPROVED, 409, 'La referencia ya está pagada.');
            abort_if($charge->status === Charge::REJECTED || $charge->is_expired, 409, 'La referencia caducó; no se puede recibir el pago. El alumno debe generar una nueva.');
            // The method is the student's choice: Control Escolar cannot switch a card reference to cash.
            abort_if($charge->payment_method !== null && $charge->payment_method !== Charge::CASH, 409, 'El alumno eligió pagar con '.mb_strtolower($charge->method_label).'; no se puede registrar efectivo en esta referencia.');

            $received = Money::toCents($amountReceived);
            $total = $charge->amountCents();
            abort_if($received < $total, 422, 'La cantidad recibida ($'.Money::fromCents($received).') es menor al total de la referencia ($'.$charge->amount.').');

            $transaction = $charge->transactions()->create([
                'method' => Charge::CASH,
                'status' => PaymentTransaction::APPROVED,
                'amount' => $charge->amount,
                'amount_received' => Money::fromCents($received),
                'change_given' => Money::fromCents($received - $total),
                'recorded_by' => $actor['type'] === 'user' ? (int) $actor['id'] : null,
            ]);

            $charge->status = Charge::APPROVED;
            $charge->payment_method = Charge::CASH;
            $charge->paid_at = now();
            $this->charges->markReviewed($charge, $actor);
            $charge->save();
            Audit::record($charge, 'cash_received', Charge::PENDING, Charge::APPROVED, $actor, null, [
                'received' => $transaction->amount_received, 'change' => $transaction->change_given,
            ]);

            return ['charge' => $charge, 'transaction' => $transaction];
        });
    }

    private function payableCharge(int $chargeId): Charge
    {
        $charge = $this->charges->lock($chargeId);
        abort_if($charge->status === Charge::APPROVED, 409, 'Esta referencia ya está pagada.');
        abort_if($charge->status === Charge::REJECTED, 409, 'Esta referencia fue rechazada. Genera una nueva.');
        abort_if($charge->is_expired, 409, 'Esta referencia caducó. Genera una nueva.');

        return $charge;
    }
}
