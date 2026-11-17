<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Invoicing\InvoiceService;
use App\Invoicing\SatCatalogs;
use App\Models\Charge;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Contract: Payments Service — invoicing (CFDI 4.0 through the PAC TimbraCFDI)
 *  GET  /api/invoicing/catalogs                 any user: regimes, CFDI uses, cancel reasons
 *  GET  /api/charges/{id}/invoice               admin / owner student: invoices of the reference + last fiscal data
 *  POST /api/charges/{id}/invoice               admin / owner student: {rfc, name, zip, regime, cfdi_use} → stamps
 *  GET  /api/invoices                           admin: filters status, student_id, q (uuid, rfc, name, reference)
 *  GET  /api/invoices/{id}                      admin / owner student: printable data (QR, seals, original chain)
 *  GET  /api/invoices/{id}/xml                  admin / owner student: the stamped XML file
 *  POST /api/invoices/{id}/cancel               admin: {reason: 01|02|03|04, replacement_uuid?}
 *  GET  /api/invoices/{id}/sat-status           admin: live status at the SAT
 */
class InvoiceController extends Controller
{
    public function catalogs(): JsonResponse
    {
        return response()->json(SatCatalogs::toArray() + ['issuer' => [
            'rfc' => config('invoicing.issuer.rfc'),
            'name' => config('invoicing.issuer.name'),
            'test_environment' => str_contains((string) config('invoicing.pac.url'), 'pruebas.'),
        ]]);
    }

    public function forCharge(Request $request, InvoiceService $invoices, int $id): JsonResponse
    {
        $charge = $this->ownedCharge($request, $id);

        return response()->json([
            'charge_id' => $charge->id,
            'can_invoice' => $charge->status === Charge::APPROVED
                && ! Invoice::where('charge_id', $charge->id)->where('status', Invoice::STAMPED)->exists(),
            'invoices' => Invoice::where('charge_id', $charge->id)->where('status', '!=', Invoice::STAMPING)->orderByDesc('id')->get(),
            'last_receiver' => $invoices->lastReceiver($charge->student_id),
        ]);
    }

    public function store(Request $request, InvoiceService $invoices, int $id): JsonResponse
    {
        $this->ownedCharge($request, $id);

        $request->merge([
            'rfc' => mb_strtoupper(preg_replace('/\s+/', '', (string) $request->input('rfc', ''))),
        ]);
        $generic = $request->input('rfc') === SatCatalogs::GENERIC_RFC;

        $data = $request->validate([
            'rfc' => ['required', 'string', 'between:12,13', function ($attr, $value, $fail) {
                if (! SatCatalogs::validRfc($value)) {
                    $fail('El RFC no tiene un formato válido (12 caracteres persona moral, 13 persona física).');
                }
            }],
            'name' => ['required', 'string', 'max:254'],
            'zip' => [$generic ? 'nullable' : 'required', 'digits:5'],
            'regime' => [$generic ? 'nullable' : 'required', Rule::in(array_map('strval', array_keys(SatCatalogs::REGIMES)))],
            'cfdi_use' => [$generic ? 'nullable' : 'required', Rule::in(array_keys(SatCatalogs::USES))],
            'amount' => ['prohibited'],
        ], [
            'rfc.required' => 'Captura el RFC (o usa XAXX010101000 si no tienes).',
            'rfc.between' => 'El RFC debe tener 12 o 13 caracteres.',
            'name.required' => 'Captura el nombre o razón social tal como aparece en tu Constancia de Situación Fiscal.',
            'zip.required' => 'Captura el código postal de tu domicilio fiscal.',
            'zip.digits' => 'El código postal debe tener 5 dígitos.',
            'regime.required' => 'Elige tu régimen fiscal.',
            'regime.in' => 'Régimen fiscal inválido.',
            'cfdi_use.required' => 'Elige el uso del CFDI.',
            'cfdi_use.in' => 'Uso de CFDI inválido.',
            'amount.prohibited' => 'El monto no se puede enviar: se toma de la referencia pagada.',
        ]);

        if (! $generic) {
            $moral = SatCatalogs::isPersonaMoral($data['rfc']);
            [$regimeName, $fisica, $isMoral] = SatCatalogs::REGIMES[$data['regime']];
            abort_unless($moral ? $isMoral : $fisica, 422, "El régimen {$data['regime']} ({$regimeName}) no aplica a una persona ".($moral ? 'moral' : 'física').'.');
            abort_unless(in_array($data['regime'], SatCatalogs::USES[$data['cfdi_use']][1], true), 422, "El uso {$data['cfdi_use']} no está permitido para el régimen {$data['regime']}.");
            abort_if($data['regime'] === '616', 422, 'El régimen 616 (sin obligaciones fiscales) se usa solo con el RFC genérico XAXX010101000.');
        }

        $invoice = $invoices->issue($id, $data, $this->auditActor($request));

        return response()->json([
            'message' => "Factura timbrada. Folio fiscal {$invoice->uuid}.",
            'invoice' => $invoice,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');

        $query = Invoice::query()->with('charge:id,reference,concept,status,payment_method,expires_at,reviewed_at')->where('status', '!=', Invoice::STAMPING)->orderByDesc('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($studentId = $request->query('student_id')) {
            $query->where('student_id', (int) $studentId);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(fn ($w) => $w->where('uuid', 'like', "%{$q}%")
                ->orWhere('receiver_rfc', 'like', "%{$q}%")
                ->orWhere('receiver_name', 'like', "%{$q}%")
                ->orWhereHas('charge', fn ($c) => $c->where('reference', 'like', "%{$q}%")));
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = $this->ownedInvoice($request, $id);
        abort_if($invoice->status === Invoice::FAILED, 409, 'Esta factura no se timbró: '.$invoice->error_message);

        return response()->json($invoice->load('charge.documentType')->printable());
    }

    public function xml(Request $request, int $id): Response
    {
        $invoice = $this->ownedInvoice($request, $id);
        abort_if(! $invoice->xml, 404, 'La factura no tiene XML timbrado.');

        return response($invoice->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->series.$invoice->folio.'_'.$invoice->uuid.'.xml"',
        ]);
    }

    public function cancel(Request $request, InvoiceService $invoices, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_map('strval', array_keys(SatCatalogs::CANCEL_REASONS)))],
            'replacement_uuid' => ['nullable', 'uuid'],
        ], [
            'reason.required' => 'Elige el motivo de cancelación.',
            'reason.in' => 'Motivo de cancelación inválido.',
            'replacement_uuid.uuid' => 'El folio fiscal de sustitución no es un UUID válido.',
        ]);

        $invoice = $invoices->cancel($id, $data['reason'], $data['replacement_uuid'] ?? null, $this->auditActor($request));

        return response()->json(['message' => 'Cancelación enviada al SAT: '.$invoice->cancel_message, 'invoice' => $invoice]);
    }

    public function satStatus(Request $request, InvoiceService $invoices, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $invoice = Invoice::find($id);
        abort_if(! $invoice, 404, 'La factura no existe.');

        return response()->json($invoices->satStatus($invoice));
    }

    private function ownedCharge(Request $request, int $id): Charge
    {
        abort_unless(in_array($this->actor($request)['role'], ['admin', 'student'], true), 403, 'No tienes permiso para esta operación.');
        $charge = Charge::find($id);
        abort_if(! $charge, 404, 'La referencia de pago no existe.');
        $this->requireOwnerOrAdmin($request, $charge->student_id);

        return $charge;
    }

    private function ownedInvoice(Request $request, int $id): Invoice
    {
        abort_unless(in_array($this->actor($request)['role'], ['admin', 'student'], true), 403, 'No tienes permiso para esta operación.');
        $invoice = Invoice::find($id);
        abort_if(! $invoice || $invoice->status === Invoice::STAMPING, 404, 'La factura no existe.');
        $this->requireOwnerOrAdmin($request, $invoice->student_id);

        return $invoice;
    }
}
