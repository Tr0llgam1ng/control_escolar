<?php

namespace App\Invoicing;

use App\Models\Charge;
use App\Models\Invoice;
use App\Payments\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoicing of paid references: CFDI 4.0 built here, sealed and stamped by the PAC.
 *
 * Rules:
 *  - only an approved (paid) reference can be invoiced
 *  - one valid invoice per reference; after a cancellation a new one can be issued
 *  - the amount is always the paid amount of the reference, never a client value
 *  - a PAC rejection is kept as a "failed" invoice with the SAT error, and nothing else changes
 */
class InvoiceService
{
    public function __construct(private TimbraCfdiClient $pac, private CfdiBuilder $builder) {}

    /**
     * @param  array{rfc: string, name: string, zip?: ?string, regime: string, cfdi_use: string}  $receiver  already validated
     * @param  array{type: string, id: string|int|null}  $actor
     */
    public function issue(int $chargeId, array $receiver, array $actor): Invoice
    {
        // 1) Reserve the folio inside a lock so two clicks cannot stamp the same reference twice.
        $invoice = DB::transaction(function () use ($chargeId, $receiver, $actor) {
            $charge = Charge::whereKey($chargeId)->lockForUpdate()->first();
            abort_if(! $charge, 404, 'La referencia de pago no existe.');
            abort_unless($charge->status === Charge::APPROVED, 409, 'Solo se puede facturar una referencia pagada (aprobada).');
            abort_if(
                Invoice::where('charge_id', $charge->id)->where('status', Invoice::STAMPED)->exists(),
                409,
                'Esta referencia ya tiene una factura vigente. Cancélala antes de emitir otra.'
            );
            // Another request is stamping right now (a stale one, e.g. after a crash, stops blocking after 5 min).
            abort_if(
                Invoice::where('charge_id', $charge->id)->where('status', Invoice::STAMPING)->where('created_at', '>', now()->subMinutes(5))->exists(),
                409,
                'La factura de esta referencia se está timbrando. Espera unos segundos.'
            );

            $receiver = $this->normalizeReceiver($receiver);

            return Invoice::create([
                'charge_id' => $charge->id,
                'student_id' => $charge->student_id,
                'status' => Invoice::STAMPING,
                'series' => config('invoicing.series'),
                'issuer_rfc' => config('invoicing.issuer.rfc'),
                'receiver_rfc' => $receiver['rfc'],
                'receiver_name' => $receiver['name'],
                'receiver_zip' => $receiver['zip'],
                'receiver_regime' => $receiver['regime'],
                'cfdi_use' => $receiver['cfdi_use'],
                'payment_form' => config('invoicing.payment_forms.'.$charge->payment_method, '99'),
                'subtotal' => $charge->amount,
                'tax' => '0.00',
                'total' => $charge->amount,
                'created_by' => $actor['type'] === 'user' ? (int) $actor['id'] : null,
            ]);
        });

        // 2) Call the PAC outside the DB transaction (it can take seconds).
        $charge = $invoice->charge()->with('documentType')->first();
        $invoice->folio = (string) $invoice->id;

        try {
            $built = $this->builder->build([
                'series' => $invoice->series,
                'folio' => $invoice->folio,
                // Local time of the place of issue; a minute back so the PAC never sees it in the future.
                'date' => now(config('payments.timezone'))->subMinute()->format('Y-m-d\\TH:i:s'),
                'payment_form' => $invoice->payment_form,
                'total_cents' => $charge->amountCents(),
                'description' => Str::limit($charge->concept.' - Referencia '.$charge->reference, 1000, ''),
                'item_id' => $charge->documentType?->code ?? (string) $charge->document_type_id,
            ], [
                'rfc' => $invoice->receiver_rfc,
                'name' => $invoice->receiver_name,
                'zip' => $invoice->receiver_zip,
                'regime' => $invoice->receiver_regime,
                'cfdi_use' => $invoice->cfdi_use,
            ]);
            $invoice->fill(['subtotal' => $built['subtotal'], 'tax' => $built['tax'], 'total' => $built['total']]);

            $stamped = $this->pac->stamp($built['xml'], 'charge-'.$charge->id.'-inv-'.$invoice->id);
        } catch (PacException $e) {
            $invoice->fill([
                'status' => Invoice::FAILED,
                'error_code' => $e->satCode ?? ($e->pacCode !== null ? (string) $e->pacCode : null),
                'error_message' => Str::limit($e->getMessage(), 495),
            ])->save();
            Audit::record($charge, 'invoice_failed', $charge->status, $charge->status, $actor, Str::limit($e->describe(), 250), ['invoice_id' => $invoice->id]);

            abort($e->unreachable ? 503 : 422, 'No se pudo timbrar la factura: '.$e->describe());
        } catch (\Throwable $e) {
            $invoice->fill(['status' => Invoice::FAILED, 'error_message' => Str::limit($e->getMessage(), 495)])->save();

            throw $e;
        }

        $invoice->fill([
            'status' => Invoice::STAMPED,
            'uuid' => $stamped['uuid'],
            'xml' => $stamped['xml'],
            'qr_png' => $stamped['qr_png_base64'],
            'original_chain' => $stamped['original_chain'],
            'stamped_at' => now(),
        ])->save();
        Audit::record($charge, 'invoice_stamped', $charge->status, $charge->status, $actor, null, [
            'invoice_id' => $invoice->id, 'uuid' => $invoice->uuid, 'receiver_rfc' => $invoice->receiver_rfc,
        ]);

        return $invoice->fresh();
    }

    /** Control Escolar cancels a stamped invoice before the SAT (motive 01 needs the replacement UUID). */
    public function cancel(int $invoiceId, string $reason, ?string $replacementUuid, array $actor): Invoice
    {
        $invoice = Invoice::find($invoiceId);
        abort_if(! $invoice, 404, 'La factura no existe.');
        abort_unless($invoice->status === Invoice::STAMPED, 409, 'Solo se puede cancelar una factura timbrada vigente.');
        abort_if($reason === '01' && ! $replacementUuid, 422, 'El motivo 01 requiere el folio fiscal (UUID) de la factura que la sustituye.');

        try {
            $result = $this->pac->cancel($invoice->issuer_rfc, $invoice->uuid, $reason, $reason === '01' ? $replacementUuid : null);
        } catch (PacException $e) {
            abort($e->unreachable ? 503 : 422, 'El PAC no aceptó la cancelación: '.$e->describe());
        }

        $invoice->fill([
            'status' => Invoice::CANCELLED,
            'cancel_reason' => $reason,
            'cancel_message' => Str::limit($result['message'], 250),
            'cancel_ack' => $result['ack_xml'],
            'cancelled_at' => now(),
            'cancelled_by' => $actor['type'] === 'user' ? (int) $actor['id'] : null,
        ])->save();

        $charge = $invoice->charge;
        Audit::record($charge, 'invoice_cancelled', $charge->status, $charge->status, $actor, SatCatalogs::CANCEL_REASONS[$reason] ?? $reason, [
            'invoice_id' => $invoice->id, 'uuid' => $invoice->uuid, 'pac_message' => $result['message'],
        ]);

        return $invoice->fresh();
    }

    /** SAT status of a stamped invoice (asked live to the SAT through the PAC). */
    public function satStatus(Invoice $invoice): array
    {
        abort_if(! $invoice->uuid, 409, 'La factura no tiene folio fiscal.');

        try {
            return $this->pac->satStatus($invoice->uuid);
        } catch (PacException $e) {
            abort($e->unreachable ? 503 : 422, 'No se pudo consultar el SAT: '.$e->describe());
        }
    }

    /** Fiscal data last used by the student, to prefill the form. */
    public function lastReceiver(int $studentId): ?array
    {
        $last = Invoice::where('student_id', $studentId)->where('status', Invoice::STAMPED)->latest('id')->first();

        return $last ? [
            'rfc' => $last->receiver_rfc,
            'name' => $last->receiver_name,
            'zip' => $last->receiver_zip,
            'regime' => $last->receiver_regime,
            'cfdi_use' => $last->cfdi_use,
        ] : null;
    }

    /** Uppercase, single spaces; the generic RFC forces regime 616 / use S01 / issuer zip. */
    private function normalizeReceiver(array $r): array
    {
        $rfc = mb_strtoupper(trim($r['rfc']));
        $name = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($r['name'])));

        if ($rfc === SatCatalogs::GENERIC_RFC) {
            return ['rfc' => $rfc, 'name' => $name, 'zip' => config('invoicing.issuer.zip'), 'regime' => '616', 'cfdi_use' => 'S01'];
        }

        return ['rfc' => $rfc, 'name' => $name, 'zip' => $r['zip'], 'regime' => $r['regime'], 'cfdi_use' => $r['cfdi_use']];
    }
}
