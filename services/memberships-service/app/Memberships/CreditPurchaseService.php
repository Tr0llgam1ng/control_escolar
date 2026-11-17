<?php

namespace App\Memberships;

use App\Models\CreditPack;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Services\PaymentsClient;
use Illuminate\Support\Facades\DB;

/**
 * Money → credits. A pack is paid as a normal reference in the Payments Service (card or cash);
 * this service only exchanges an APPROVED reference for credits, once (charge_id is unique).
 * If the amount paid does not match the price of the pack, nothing is credited: it goes to review.
 */
class CreditPurchaseService
{
    public function __construct(private PaymentsClient $payments, private CreditLedger $ledger, private AccessService $access) {}

    /** @return array{0: CreditPurchase, 1: bool} [purchase, created] */
    public function register(int $userId, int $studentId, int $chargeId, string $packCode): array
    {
        $pack = CreditPack::where('code', $packCode)->where('is_active', true)->first();
        abort_if(! $pack, 422, 'El paquete de créditos no está disponible.');

        $existing = CreditPurchase::where('charge_id', $chargeId)->first();
        if ($existing) {
            abort_unless($existing->user_id === $userId, 409, 'Esa referencia ya está registrada por otra cuenta.');

            return [$this->sync($existing), false];
        }

        $status = $this->payments->chargeStatus($chargeId);
        abort_unless((int) $status['student_id'] === $studentId, 403, 'La referencia de pago no es tuya.');
        abort_unless(($status['category'] ?? null) === 'credits' && ($status['document_type_code'] ?? null) === $pack->code, 422,
            'La referencia no corresponde a este paquete de créditos.');

        $bonusPct = $this->access->value($userId, 'credits.bonus_pct');
        $plan = $this->access->context($userId)['plan'];

        $purchase = CreditPurchase::create([
            'user_id' => $userId,
            'student_id' => $studentId,
            'credit_pack_id' => $pack->id,
            'charge_id' => $chargeId,
            'reference' => $status['reference'] ?? null,
            'amount' => $status['amount'],
            'credits' => $pack->credits,
            'bonus_pct' => $bonusPct,
            'bonus_credits' => intdiv($pack->credits * $bonusPct, 100),
            'status' => CreditPurchase::PENDING,
            'status_reason' => $bonusPct ? "Incluye {$bonusPct}% de regalo del plan {$plan->name}." : null,
        ]);

        return [$this->apply($purchase, $status), true];
    }

    /** Asks payments-service again; credits the wallet when the reference was approved. */
    public function sync(CreditPurchase $purchase): CreditPurchase
    {
        if ($purchase->status !== CreditPurchase::PENDING) {
            return $purchase->load('pack');
        }

        return $this->apply($purchase, $this->payments->chargeStatus($purchase->charge_id));
    }

    /** @return int how many purchases were credited */
    public function syncPending(int $userId): int
    {
        $credited = 0;
        foreach (CreditPurchase::where('user_id', $userId)->where('status', CreditPurchase::PENDING)->get() as $purchase) {
            $credited += $this->sync($purchase)->status === CreditPurchase::CREDITED ? 1 : 0;
        }

        return $credited;
    }

    /** Control Escolar decides a purchase in review: credit it anyway or reject it. */
    public function resolve(CreditPurchase $purchase, string $action, string $reason, array $actor): CreditPurchase
    {
        return DB::transaction(function () use ($purchase, $action, $reason, $actor) {
            $purchase = CreditPurchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            abort_unless($purchase->status === CreditPurchase::REVIEW, 409, 'Solo se resuelven compras en revisión.');

            if ($action === 'credit') {
                $this->creditWallet($purchase, $actor, " · autorizado por Control Escolar: {$reason}");
            } else {
                $purchase->status = CreditPurchase::REJECTED;
                $purchase->status_reason = "Rechazada por Control Escolar: {$reason}";
                $purchase->save();
            }

            return $purchase->load('pack');
        });
    }

    private function apply(CreditPurchase $purchase, array $status): CreditPurchase
    {
        return DB::transaction(function () use ($purchase, $status) {
            // Lock and re-check: two syncs at the same time must not credit twice.
            $purchase = CreditPurchase::with('pack')->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== CreditPurchase::PENDING) {
                return $purchase;
            }
            $purchase->checked_at = now();

            if (! empty($status['paid'])) {
                $paid = self::cents((string) $status['amount']);
                $price = self::cents((string) $purchase->pack->price);
                if ($paid !== $price) {
                    $purchase->status = CreditPurchase::REVIEW;
                    $purchase->status_reason = 'El monto pagado ($'.$status['amount'].') no coincide con el precio del paquete ($'.$purchase->pack->price.'). Control Escolar debe revisarla.';
                    $purchase->save();

                    return $purchase;
                }
                $this->creditWallet($purchase, ['type' => 'service', 'id' => 'payments-service']);

                return $purchase;
            }

            if ($status['status'] === 'rejected' || ($status['status'] === 'pending' && ! empty($status['is_expired']))) {
                $purchase->status = CreditPurchase::REJECTED;
                $purchase->status_reason = 'La referencia caducó o fue rechazada sin pago.';
            }
            $purchase->save();

            return $purchase;
        });
    }

    private function creditWallet(CreditPurchase $purchase, array $actor, string $note = ''): void
    {
        $name = $purchase->pack->name;
        $this->ledger->credit($purchase->user_id, $purchase->credits, CreditTransaction::PURCHASE,
            "Compra: {$name} (ref. {$purchase->reference}){$note}", $actor, 'credit_purchase', $purchase->id);
        if ($purchase->bonus_credits > 0) {
            $this->ledger->credit($purchase->user_id, $purchase->bonus_credits, CreditTransaction::BONUS,
                "Regalo del plan: {$purchase->bonus_pct}% sobre {$name}", $actor, 'credit_purchase', $purchase->id);
        }
        $purchase->status = CreditPurchase::CREDITED;
        $purchase->credited_at = now();
        $purchase->save();
    }

    public static function cents(string $amount): int
    {
        [$int, $dec] = array_pad(explode('.', trim($amount), 2), 2, '0');

        return ((int) $int) * 100 + (int) str_pad(substr($dec, 0, 2), 2, '0');
    }
}
