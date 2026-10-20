<?php

namespace App\Payments;

use App\Models\Charge;
use App\Models\DocumentType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** References (charges) and the status rules Control Escolar applies to them. */
class ChargeService
{
    public function __construct(private ReferenceGenerator $references) {}

    /**
     * Generates a payment reference for a document. If the student already has a live
     * pending reference for the same document, that one is returned instead of a duplicate.
     *
     * @param  array{type:string, id:string|int|null}  $actor
     * @return array{0: Charge, 1: bool} [charge, created]
     */
    public function create(int $studentId, DocumentType $type, array $actor): array
    {
        $open = Charge::where('student_id', $studentId)
            ->where('document_type_id', $type->id)
            ->where('status', Charge::PENDING)
            ->where('expires_at', '>', now())
            ->first();
        if ($open) {
            return [$open, false];
        }

        $maxAttempts = (int) config('payments.reference_max_attempts', 5);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $charge = DB::transaction(function () use ($studentId, $type, $actor) {
                    $charge = new Charge([
                        'document_type_id' => $type->id,
                        'concept' => $type->name,
                        'expires_at' => now()->addHours((int) config('payments.reference_ttl_hours', 72)),
                        'created_by' => $actor['type'] === 'user' ? (int) $actor['id'] : null,
                    ]);
                    // Server-controlled fields, never mass-assigned: the price comes from the catalog of documents.
                    $charge->forceFill([
                        'reference' => $this->references->generate(),
                        'student_id' => $studentId,
                        'amount' => Money::fromCents(Money::toCents((string) $type->price)),
                        'currency' => config('payments.currency', 'MXN'),
                        'status' => Charge::PENDING,
                    ])->save();

                    Audit::record($charge, 'created', null, Charge::PENDING, $actor, null, [
                        'amount' => $charge->amount, 'expires_at' => $charge->expires_at->toIso8601String(),
                    ]);

                    return $charge;
                });

                return [$charge->fresh(), true];
            } catch (UniqueConstraintViolationException) {
                Log::warning('Payment reference collision, retrying', ['attempt' => $attempt]);
            }
        }

        Log::error('Could not generate a unique payment reference', ['attempts' => $maxAttempts]);
        throw new HttpException(500, 'No fue posible generar una referencia única. Intenta de nuevo.');
    }

    /**
     * Status change made by Control Escolar:
     *  approved → only if a payment was verified (validated card or cash received)
     *  rejected → only if nothing was paid and the reference already expired
     *  pending  → only if nothing was paid and the reference is still alive
     * Any decision marks the reference as reviewed.
     */
    public function changeStatus(int $chargeId, string $status, ?string $reason, array $actor): Charge
    {
        return DB::transaction(function () use ($chargeId, $status, $reason, $actor) {
            $charge = $this->lock($chargeId);
            $paid = $charge->hasApprovedPayment();
            $from = $charge->status;

            match ($status) {
                Charge::APPROVED => abort_unless($paid, 422, $charge->payment_method === Charge::CASH
                    ? 'Primero registra el efectivo recibido: no hay un pago comprobado para esta referencia.'
                    : 'No hay un pago comprobado para esta referencia; no se puede aprobar.'),
                Charge::REJECTED => $paid
                    ? abort(409, 'La referencia tiene un pago comprobado; no se puede rechazar.')
                    : abort_unless($charge->is_expired, 422, 'La referencia sigue vigente hasta el '.$this->localDate($charge).'; solo se rechaza cuando caduca sin pago.'),
                Charge::PENDING => $paid
                    ? abort(409, 'La referencia ya fue pagada; no puede volver a pendiente.')
                    : abort_if($charge->is_expired, 422, 'La referencia ya caducó; no puede seguir pendiente. Recházala.'),
            };

            $charge->status = $status;
            $charge->status_reason = $reason;
            $this->markReviewed($charge, $actor);
            $charge->save();

            Audit::record($charge, $from === $status ? 'reviewed' : 'status_changed', $from, $status, $actor, $reason);

            return $charge;
        });
    }

    /** Control Escolar confirms it checked the reference (e.g. a card payment already reflected). */
    public function review(int $chargeId, array $actor): Charge
    {
        return DB::transaction(function () use ($chargeId, $actor) {
            $charge = $this->lock($chargeId);
            $this->markReviewed($charge, $actor);
            $charge->save();
            Audit::record($charge, 'reviewed', $charge->status, $charge->status, $actor);

            return $charge;
        });
    }

    public function lock(int $chargeId): Charge
    {
        $charge = Charge::whereKey($chargeId)->lockForUpdate()->first();
        abort_if(! $charge, 404, 'La referencia de pago no existe.');

        return $charge;
    }

    public function markReviewed(Charge $charge, array $actor): void
    {
        $charge->reviewed_at = now();
        $charge->reviewed_by = $actor['type'] === 'user' ? (int) $actor['id'] : null;
    }

    private function localDate(Charge $charge): string
    {
        return $charge->expires_at->copy()->setTimezone(config('payments.timezone'))->format('d/m/Y H:i');
    }
}
